<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RMH_InvoiceNinja_Client {
    /**
     * Base URL for the Invoice Ninja instance (without trailing slash).
     *
     * @var string
     */
    private $base_url;

    /**
     * API token used for authenticating requests.
     *
     * @var string
     */
    private $api_token;

    /**
     * Cache lifetime for invitation links in seconds.
     *
     * @var int
     */
    private $cache_ttl;

    /**
     * Constructor.
     *
     * @param string $base_url      Invoice Ninja base URL.
     * @param string $api_token     API token for Invoice Ninja v5.
     * @param int    $cache_minutes Cache duration in minutes.
     */
    public function __construct( string $base_url, string $api_token, int $cache_minutes = 10 ) {
        $this->base_url  = rtrim( $base_url, '/' );
        $this->api_token = $api_token;
        $this->cache_ttl = max( 0, absint( $cache_minutes ) ) * MINUTE_IN_SECONDS;
    }

    /**
     * Retrieve the client portal link for a given invoice.
     *
     * @param mixed $invoice_id Invoice identifier as provided by Invoice Ninja.
     * @return string|null
     */
    public function get_invoice_portal_link( $invoice_id ): ?string {
        $details = $this->get_invoice_portal_details( $invoice_id );
        if ( is_array( $details ) && ! empty( $details['link'] ) ) {
            return $details['link'];
        }

        return null;
    }

    /**
     * Retrieve the portal link and payment status for a given invoice.
     *
     * @param mixed $invoice_id Invoice identifier as provided by Invoice Ninja.
     * @return array{link:string,is_paid:bool|null}|null
     */
    public function get_invoice_portal_details( $invoice_id ): ?array {
        $invoice = is_scalar( $invoice_id ) ? trim( (string) $invoice_id ) : '';
        if ( $invoice === '' ) {
            return null;
        }

        $cache_key = 'rmh_in_invit_' . md5( $invoice );
        if ( $this->cache_ttl > 0 ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                $link    = isset( $cached['link'] ) && is_string( $cached['link'] ) ? trim( $cached['link'] ) : '';
                $is_paid = null;
                if ( array_key_exists( 'is_paid', $cached ) ) {
                    if ( $cached['is_paid'] === null ) {
                        $is_paid = null;
                    } else {
                        $is_paid = (bool) $cached['is_paid'];
                    }
                }

                if ( $link !== '' ) {
                    return [
                        'link'    => $link,
                        'is_paid' => $is_paid,
                    ];
                }
            } elseif ( is_string( $cached ) && $cached !== '' ) {
                return [
                    'link'    => trim( $cached ),
                    'is_paid' => null,
                ];
            }
        }

        $url  = $this->base_url . '/api/v1/invoices/' . rawurlencode( $invoice ) . '?include=invitations';
        $args = [
            'timeout' => 10,
            'headers' => [
                'X-API-Token'      => $this->api_token,
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept'           => 'application/json',
                'User-Agent'       => 'RMH-Order-Tracker (+WordPress)',
            ],
        ];

        $response = wp_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) {
            error_log( '[RMH Invoice Ninja] HTTP error for invoice ' . sanitize_text_field( $invoice ) . ': ' . $response->get_error_message() );
            return null;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 ) {
            error_log( '[RMH Invoice Ninja] Unexpected status ' . $status . ' for invoice ' . sanitize_text_field( $invoice ) . '.' );
            return null;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            error_log( '[RMH Invoice Ninja] Invalid JSON response for invoice ' . sanitize_text_field( $invoice ) . '.' );
            return null;
        }

        $invitation = $this->extract_first_invitation( $data );
        if ( ! $invitation ) {
            error_log( '[RMH Invoice Ninja] No invitation found for invoice ' . sanitize_text_field( $invoice ) . '.' );
            return null;
        }

        $link = '';
        if ( ! empty( $invitation['link'] ) ) {
            $link = (string) $invitation['link'];
        } elseif ( ! empty( $invitation['key'] ) ) {
            $link = $this->base_url . '/client/invoice/' . rawurlencode( (string) $invitation['key'] );
        }

        $link = trim( $link );
        if ( $link === '' ) {
            error_log( '[RMH Invoice Ninja] Invitation missing link for invoice ' . sanitize_text_field( $invoice ) . '.' );
            return null;
        }

        $is_paid = $this->determine_invoice_paid_status( $data );

        $details = [
            'link'    => $link,
            'is_paid' => $is_paid,
        ];

        if ( $this->cache_ttl > 0 ) {
            set_transient( $cache_key, $details, $this->cache_ttl );
        }

        return $details;
    }

    /**
     * Extract the first invitation entry from the API response.
     *
     * @param array $data Decoded response data.
     * @return array|null
     */
    private function extract_first_invitation( array $data ): ?array {
        $queue = [ $data ];
        while ( $queue ) {
            $current = array_shift( $queue );
            if ( ! is_array( $current ) ) {
                continue;
            }

            if ( isset( $current['invitations'] ) && is_array( $current['invitations'] ) ) {
                $list = $current['invitations'];
                if ( isset( $list['data'] ) && is_array( $list['data'] ) ) {
                    $list = $list['data'];
                }
                foreach ( $list as $invitation ) {
                    if ( is_array( $invitation ) ) {
                        return $invitation;
                    }
                }
            }

            foreach ( $current as $value ) {
                if ( is_array( $value ) ) {
                    $queue[] = $value;
                }
            }
        }

        return null;
    }

    /**
     * Determine whether the invoice is paid based on API payload.
     *
     * @param array $payload Invoice response payload.
     * @return bool|null True when paid, false when unpaid, null when unknown.
     */
    private function determine_invoice_paid_status( array $payload ): ?bool {
        $invoice = $this->extract_invoice_record( $payload );
        if ( ! is_array( $invoice ) ) {
            return null;
        }

        $explicit_flag = $this->extract_value_case_insensitive( $invoice, 'is_paid' );
        if ( is_bool( $explicit_flag ) ) {
            return $explicit_flag;
        }
        if ( is_numeric( $explicit_flag ) ) {
            return ( (int) $explicit_flag ) === 1;
        }
        if ( is_string( $explicit_flag ) ) {
            $flag = strtolower( trim( $explicit_flag ) );
            if ( in_array( $flag, [ '1', 'true', 'yes', 'paid' ], true ) ) {
                return true;
            }
            if ( in_array( $flag, [ '0', 'false', 'no', 'unpaid' ], true ) ) {
                return false;
            }
        }

        $balance = $this->extract_numeric_value( $invoice, [ 'balance', 'balance_raw', 'outstanding', 'amount_due', 'amountdue' ] );
        if ( null !== $balance ) {
            if ( abs( $balance ) <= 0.0001 ) {
                return true;
            }
            if ( $balance > 0 ) {
                return false;
            }
        }

        $amount       = $this->extract_numeric_value( $invoice, [ 'amount', 'total', 'amount_raw' ] );
        $paid_to_date = $this->extract_numeric_value( $invoice, [ 'paid_to_date', 'paidToDate', 'paid_amount' ] );
        if ( null !== $amount && null !== $paid_to_date ) {
            if ( $amount <= 0 && $paid_to_date >= 0 ) {
                return true;
            }
            if ( ( $amount - $paid_to_date ) <= 0.0001 ) {
                return true;
            }
            if ( $paid_to_date < $amount ) {
                return false;
            }
        }

        $status = $this->extract_string_value( $invoice, [ 'status', 'invoice_status', 'status_label' ] );
        if ( null !== $status ) {
            $normalized = strtolower( trim( $status ) );
            if ( in_array( $normalized, [ 'paid', 'paid in full', 'paid_in_full', 'paid-in-full' ], true ) ) {
                return true;
            }
            if ( in_array( $normalized, [ 'partial', 'past_due', 'overdue', 'sent', 'draft', 'viewed', 'approved', 'unpaid' ], true ) ) {
                return false;
            }
        }

        return null;
    }

    /**
     * Extract the invoice record from the payload.
     *
     * @param array $payload Invoice response payload.
     * @return array
     */
    private function extract_invoice_record( array $payload ): array {
        if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
            $record = $payload['data'];
            if ( isset( $record['data'] ) && is_array( $record['data'] ) ) {
                $record = $record['data'];
            }
            return $record;
        }

        return $payload;
    }

    /**
     * Extract a numeric value using case-insensitive keys.
     *
     * @param array $source Source array.
     * @param array $fields Field names to inspect.
     * @return float|null
     */
    private function extract_numeric_value( array $source, array $fields ): ?float {
        foreach ( $fields as $field ) {
            $value = $this->extract_value_case_insensitive( $source, $field );
            if ( is_numeric( $value ) ) {
                return (float) $value;
            }
            if ( is_string( $value ) ) {
                $normalized = str_replace( ',', '.', $value );
                if ( is_numeric( $normalized ) ) {
                    return (float) $normalized;
                }
            }
        }

        return null;
    }

    /**
     * Extract a string value using case-insensitive keys.
     *
     * @param array $source Source array.
     * @param array $fields Field names to inspect.
     * @return string|null
     */
    private function extract_string_value( array $source, array $fields ): ?string {
        foreach ( $fields as $field ) {
            $value = $this->extract_value_case_insensitive( $source, $field );
            if ( is_string( $value ) && $value !== '' ) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Retrieve a value from the array by performing a case-insensitive key lookup.
     *
     * @param array  $source Source array.
     * @param string $field  Field name.
     * @return mixed|null
     */
    private function extract_value_case_insensitive( array $source, string $field ) {
        $lower = strtolower( $field );
        foreach ( $source as $key => $value ) {
            if ( is_string( $key ) && strtolower( $key ) === $lower ) {
                return $value;
            }
        }

        return null;
    }
}
