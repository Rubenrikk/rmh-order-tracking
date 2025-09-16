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
        $invoice = is_scalar( $invoice_id ) ? trim( (string) $invoice_id ) : '';
        if ( $invoice === '' ) {
            return null;
        }

        $cache_key = 'rmh_in_invit_' . md5( $invoice );
        if ( $this->cache_ttl > 0 ) {
            $cached = get_transient( $cache_key );
            if ( is_string( $cached ) && $cached !== '' ) {
                return $cached;
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

        if ( $this->cache_ttl > 0 ) {
            set_transient( $cache_key, $link, $this->cache_ttl );
        }

        return $link;
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
}
