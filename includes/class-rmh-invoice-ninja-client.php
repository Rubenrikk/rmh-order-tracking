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

        $cache_key         = 'rmh_in_invit_' . md5( $invoice );
        $resolved_cache_key = 'rmh_in_invoice_id_' . md5( $invoice );

        if ( $this->cache_ttl > 0 ) {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) ) {
                if ( ! empty( $cached['missing'] ) ) {
                    return null;
                }

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

        $resolved_invoice = $invoice;
        if ( $this->cache_ttl > 0 ) {
            $cached_resolved = get_transient( $resolved_cache_key );
            if ( is_string( $cached_resolved ) && $cached_resolved !== '' ) {
                if ( '__missing__' === $cached_resolved ) {
                    return null;
                }

                $resolved_invoice = $cached_resolved;
            }
        }

        $attempted_number_lookup = false;

        while ( true ) {
            $response = $this->request_invoice_by_id( $resolved_invoice );
            if ( is_wp_error( $response ) ) {
                error_log( '[RMH Invoice Ninja] HTTP error for invoice ' . sanitize_text_field( $resolved_invoice ) . ': ' . $response->get_error_message() );
                return null;
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            if ( $status >= 200 && $status < 300 ) {
                $body = wp_remote_retrieve_body( $response );
                $data = json_decode( $body, true );
                if ( ! is_array( $data ) ) {
                    error_log( '[RMH Invoice Ninja] Invalid JSON response for invoice ' . sanitize_text_field( $resolved_invoice ) . '.' );
                    return null;
                }

                $invitation = $this->extract_first_invitation( $data );
                if ( ! $invitation ) {
                    error_log( '[RMH Invoice Ninja] No invitation found for invoice ' . sanitize_text_field( $resolved_invoice ) . '.' );
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
                    error_log( '[RMH Invoice Ninja] Invitation missing link for invoice ' . sanitize_text_field( $resolved_invoice ) . '.' );
                    return null;
                }

                $is_paid = $this->determine_invoice_paid_status( $data );

                $details = [
                    'link'       => $link,
                    'is_paid'    => $is_paid,
                    'invoice_id' => $resolved_invoice,
                ];

                if ( $this->cache_ttl > 0 ) {
                    set_transient( $cache_key, $details, $this->cache_ttl );
                    set_transient( $resolved_cache_key, $resolved_invoice, $this->cache_ttl );
                }

                return [
                    'link'    => $link,
                    'is_paid' => $is_paid,
                ];
            }

            if ( ( 404 === $status || 422 === $status ) && ! $attempted_number_lookup && $resolved_invoice === $invoice ) {
                $lookup = $this->lookup_invoice_id_by_number( $invoice );
                if ( 'found' === $lookup['status'] && is_string( $lookup['id'] ) && $lookup['id'] !== '' ) {
                    $resolved_invoice       = $lookup['id'];
                    $attempted_number_lookup = true;
                    if ( $this->cache_ttl > 0 ) {
                        set_transient( $resolved_cache_key, $resolved_invoice, $this->cache_ttl );
                    }
                    continue;
                }

                if ( 'not_found' === $lookup['status'] ) {
                    $this->store_invoice_lookup_miss( $cache_key, $resolved_cache_key );
                    error_log( '[RMH Invoice Ninja] No invoice found for number ' . sanitize_text_field( $invoice ) . '.' );
                    return null;
                }

                return null;
            }

            if ( 404 === $status || 422 === $status ) {
                $this->store_invoice_lookup_miss( $cache_key, $resolved_cache_key );
                error_log( '[RMH Invoice Ninja] Invoice ' . sanitize_text_field( $resolved_invoice ) . ' not found.' );
                return null;
            }

            error_log( '[RMH Invoice Ninja] Unexpected status ' . $status . ' for invoice ' . sanitize_text_field( $resolved_invoice ) . '.' );
            return null;
        }
    }

    /**
     * Perform the Invoice Ninja API request for a specific invoice ID.
     *
     * @param string $invoice_id Invoice identifier (hashed ID).
     * @return array|WP_Error
     */
    private function request_invoice_by_id( string $invoice_id ) {
        $url = $this->base_url . '/api/v1/invoices/' . rawurlencode( $invoice_id ) . '?include=invitations';

        return wp_remote_get( $url, $this->get_default_request_args() );
    }

    /**
     * Lookup an invoice ID by its visible invoice number.
     *
     * @param string $invoice_number Human-readable invoice number.
     * @return array{status:'found'|'not_found'|'error',id:?string}
     */
    private function lookup_invoice_id_by_number( string $invoice_number ): array {
        $params = [
            'invoice_number' => $invoice_number,
            'number'         => $invoice_number,
        ];

        foreach ( $params as $param => $value ) {
            $url      = $this->base_url . '/api/v1/invoices?' . rawurlencode( $param ) . '=' . rawurlencode( $value );
            $response = wp_remote_get( $url, $this->get_default_request_args() );

            if ( is_wp_error( $response ) ) {
                error_log( '[RMH Invoice Ninja] HTTP error while searching for invoice ' . sanitize_text_field( $invoice_number ) . ': ' . $response->get_error_message() );
                return [
                    'status' => 'error',
                    'id'     => null,
                ];
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            if ( $status === 404 ) {
                continue;
            }

            if ( $status < 200 || $status >= 300 ) {
                error_log( '[RMH Invoice Ninja] Unexpected status ' . $status . ' while searching for invoice ' . sanitize_text_field( $invoice_number ) . '.' );
                return [
                    'status' => 'error',
                    'id'     => null,
                ];
            }

            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );
            if ( ! is_array( $data ) ) {
                error_log( '[RMH Invoice Ninja] Invalid JSON while searching for invoice ' . sanitize_text_field( $invoice_number ) . '.' );
                return [
                    'status' => 'error',
                    'id'     => null,
                ];
            }

            $records = $data['data'] ?? $data;
            if ( is_array( $records ) && isset( $records['data'] ) && is_array( $records['data'] ) ) {
                $records = $records['data'];
            }

            if ( is_array( $records ) && isset( $records['id'] ) && ! isset( $records[0] ) ) {
                $candidate = $this->extract_string_value( $records, [ 'id', 'hashed_id' ] );
                if ( null !== $candidate && $candidate !== '' ) {
                    return [
                        'status' => 'found',
                        'id'     => trim( (string) $candidate ),
                    ];
                }
            }

            if ( is_array( $records ) ) {
                foreach ( $records as $record ) {
                    if ( ! is_array( $record ) ) {
                        continue;
                    }

                    $candidate = $this->extract_string_value( $record, [ 'id', 'hashed_id' ] );
                    if ( null !== $candidate && $candidate !== '' ) {
                        return [
                            'status' => 'found',
                            'id'     => trim( (string) $candidate ),
                        ];
                    }
                }
            }
        }

        return [
            'status' => 'not_found',
            'id'     => null,
        ];
    }

    /**
     * Default arguments for API requests.
     *
     * @return array
     */
    private function get_default_request_args(): array {
        return [
            'timeout' => 10,
            'headers' => [
                'X-API-Token'      => $this->api_token,
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept'           => 'application/json',
                'User-Agent'       => 'RMH-Order-Tracker (+WordPress)',
            ],
        ];
    }

    /**
     * Cache a failed lookup to avoid repeated API calls for missing invoices.
     *
     * @param string $cache_key         Cache key for invitation details.
     * @param string $resolved_cache_key Cache key for resolved invoice IDs.
     * @return void
     */
    private function store_invoice_lookup_miss( string $cache_key, string $resolved_cache_key ): void {
        if ( $this->cache_ttl <= 0 ) {
            return;
        }

        set_transient( $cache_key, [ 'missing' => true ], $this->cache_ttl );
        set_transient( $resolved_cache_key, '__missing__', $this->cache_ttl );
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
