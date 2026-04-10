<?php
/**
 * LSG Customizer — Live Sale Grid appearance panel
 *
 * Registers a "Live Sale Grid" section under Appearance → Customize.
 * All values are stored as theme_mods (prefixed lsg_*) and output as
 * CSS custom properties injected inline after the grid stylesheet,
 * so the WordPress Customizer live-preview works without page reloads.
 *
 * @package LiveSale
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// Default values — single source of truth
// ============================================================
function lsg_customizer_defaults() : array {
    return [
        // Brand / accent colours — tuned to look great alongside Botiga
        'lsg_color_accent'        => '#2c6fad',   // claim button, winner CTA
        'lsg_color_accent_dark'   => '#1f4e7a',   // hover state
        'lsg_color_auction'       => '#c0392b',   // auction badge, bid button, bid value
        'lsg_color_auction_dark'  => '#922b21',   // hover
        'lsg_color_giveaway'      => '#6c3483',   // giveaway badge, button
        'lsg_color_giveaway_dark' => '#512e5f',   // hover
        'lsg_color_waitlist'      => '#1a5276',   // waitlist button
        'lsg_color_pin'           => '#b7770d',   // pinned badge / border
        // Card chrome
        'lsg_card_radius'         => '4',         // px, integer string
        'lsg_grid_columns'        => '4',         // 2-6
        'lsg_grid_gap'            => '16',        // px
    ];
}

// ============================================================
// Register Customizer settings + controls
// ============================================================
add_action( 'customize_register', 'lsg_customizer_register' );
function lsg_customizer_register( WP_Customize_Manager $wp_customize ) : void {

    $defaults = lsg_customizer_defaults();

    // ── Panel ────────────────────────────────────────────────
    $wp_customize->add_panel( 'lsg_panel', [
        'title'       => __( 'Live Sale Grid', 'livesale' ),
        'description' => __( 'Customise the appearance of the Live Sale product grid.', 'livesale' ),
        'priority'    => 160,
    ] );

    // ── Section: Colours ─────────────────────────────────────
    $wp_customize->add_section( 'lsg_section_colors', [
        'title'  => __( 'Colours', 'livesale' ),
        'panel'  => 'lsg_panel',
        'priority' => 10,
    ] );

    $color_settings = [
        'lsg_color_accent'        => __( 'Claim / Success colour', 'livesale' ),
        'lsg_color_auction'       => __( 'Auction accent colour', 'livesale' ),
        'lsg_color_giveaway'      => __( 'Giveaway accent colour', 'livesale' ),
        'lsg_color_waitlist'      => __( 'Waitlist button colour', 'livesale' ),
        'lsg_color_pin'           => __( 'Pinned product highlight', 'livesale' ),
    ];

    foreach ( $color_settings as $id => $label ) {
        $wp_customize->add_setting( $id, [
            'default'           => $defaults[ $id ],
            'sanitize_callback' => 'sanitize_hex_color',
            'transport'         => 'postMessage',
        ] );
        $wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, $id, [
            'label'   => $label,
            'section' => 'lsg_section_colors',
        ] ) );
    }

    // ── Section: Layout ──────────────────────────────────────
    $wp_customize->add_section( 'lsg_section_layout', [
        'title'  => __( 'Layout', 'livesale' ),
        'panel'  => 'lsg_panel',
        'priority' => 20,
    ] );

    // Columns
    $wp_customize->add_setting( 'lsg_grid_columns', [
        'default'           => $defaults['lsg_grid_columns'],
        'sanitize_callback' => 'absint',
        'transport'         => 'postMessage',
    ] );
    $wp_customize->add_control( 'lsg_grid_columns', [
        'label'   => __( 'Grid columns (desktop)', 'livesale' ),
        'section' => 'lsg_section_layout',
        'type'    => 'select',
        'choices' => [ '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6' ],
    ] );

    // Gap
    $wp_customize->add_setting( 'lsg_grid_gap', [
        'default'           => $defaults['lsg_grid_gap'],
        'sanitize_callback' => 'absint',
        'transport'         => 'postMessage',
    ] );
    $wp_customize->add_control( 'lsg_grid_gap', [
        'label'       => __( 'Card gap (px)', 'livesale' ),
        'section'     => 'lsg_section_layout',
        'type'        => 'range',
        'input_attrs' => [ 'min' => 4, 'max' => 40, 'step' => 2 ],
    ] );

    // Card border radius
    $wp_customize->add_setting( 'lsg_card_radius', [
        'default'           => $defaults['lsg_card_radius'],
        'sanitize_callback' => 'absint',
        'transport'         => 'postMessage',
    ] );
    $wp_customize->add_control( 'lsg_card_radius', [
        'label'       => __( 'Card corner radius (px)', 'livesale' ),
        'section'     => 'lsg_section_layout',
        'type'        => 'range',
        'input_attrs' => [ 'min' => 0, 'max' => 24, 'step' => 1 ],
    ] );
}

// ============================================================
// Output CSS custom properties — injected after grid stylesheet
// Works for both normal page load AND Customizer live preview
// ============================================================
add_action( 'wp_enqueue_scripts',      'lsg_customizer_inline_css', 20 );
add_action( 'customize_preview_init',  'lsg_customizer_postmessage_js' );

function lsg_customizer_inline_css() : void {
    // Only inject when the grid stylesheet is actually loaded on this page
    if ( ! wp_style_is( 'lsg-grid', 'enqueued' ) ) return;

    wp_add_inline_style( 'lsg-grid', lsg_build_custom_props_css() );
}

function lsg_build_custom_props_css() : string {
    $d = lsg_customizer_defaults();

    // Helper: get theme_mod with fallback to our own defaults (not theme defaults)
    $get = function( string $key ) use ( $d ) : string {
        return (string) get_theme_mod( $key, $d[ $key ] );
    };

    $accent        = $get( 'lsg_color_accent' );
    $accent_dark   = lsg_darken_hex( $accent, 0.15 );
    $auction       = $get( 'lsg_color_auction' );
    $auction_dark  = lsg_darken_hex( $auction, 0.12 );
    $giveaway      = $get( 'lsg_color_giveaway' );
    $giveaway_dark = lsg_darken_hex( $giveaway, 0.12 );
    $waitlist      = $get( 'lsg_color_waitlist' );
    $pin           = $get( 'lsg_color_pin' );
    $radius        = absint( $get( 'lsg_card_radius' ) );
    $cols          = absint( $get( 'lsg_grid_columns' ) );
    $gap           = absint( $get( 'lsg_grid_gap' ) );

    // Derive rgba versions from hex for backgrounds
    $accent_rgb   = lsg_hex_to_rgb( $accent );
    $auction_rgb  = lsg_hex_to_rgb( $auction );
    $giveaway_rgb = lsg_hex_to_rgb( $giveaway );
    $pin_rgb      = lsg_hex_to_rgb( $pin );

    return "
:root {
    --lsg-accent:          {$accent};
    --lsg-accent-dark:     {$accent_dark};
    --lsg-accent-rgb:      {$accent_rgb};
    --lsg-auction:         {$auction};
    --lsg-auction-dark:    {$auction_dark};
    --lsg-auction-rgb:     {$auction_rgb};
    --lsg-giveaway:        {$giveaway};
    --lsg-giveaway-dark:   {$giveaway_dark};
    --lsg-giveaway-rgb:    {$giveaway_rgb};
    --lsg-waitlist:        {$waitlist};
    --lsg-pin:             {$pin};
    --lsg-pin-rgb:         {$pin_rgb};
    --lsg-radius:          {$radius}px;
    --lsg-cols:            {$cols};
    --lsg-gap:             {$gap}px;
}
";
}

// ============================================================
// postMessage JS — updates CSS vars live in the Customizer iframe
// without needing a full refresh
// ============================================================
function lsg_customizer_postmessage_js() : void {
    wp_add_inline_script( 'customize-preview', "
(function( api ) {
    function setVar( varName, value ) {
        document.documentElement.style.setProperty( varName, value );
    }
    api( 'lsg_color_accent',   function(v){ v.bind(function(val){ setVar('--lsg-accent', val); }); } );
    api( 'lsg_color_auction',  function(v){ v.bind(function(val){ setVar('--lsg-auction', val); }); } );
    api( 'lsg_color_giveaway', function(v){ v.bind(function(val){ setVar('--lsg-giveaway', val); }); } );
    api( 'lsg_color_waitlist', function(v){ v.bind(function(val){ setVar('--lsg-waitlist', val); }); } );
    api( 'lsg_color_pin',      function(v){ v.bind(function(val){ setVar('--lsg-pin', val); }); } );
    api( 'lsg_card_radius',    function(v){ v.bind(function(val){ setVar('--lsg-radius', val + 'px'); }); } );
    api( 'lsg_grid_columns',   function(v){ v.bind(function(val){ setVar('--lsg-cols', val); }); } );
    api( 'lsg_grid_gap',       function(v){ v.bind(function(val){ setVar('--lsg-gap', val + 'px'); }); } );
})( wp.customize );
" );
}

// ============================================================
// Utility helpers
// ============================================================

/**
 * Convert #rrggbb / #rgb to "r, g, b" string (for rgba() in CSS).
 */
function lsg_hex_to_rgb( string $hex ) : string {
    $hex = ltrim( $hex, '#' );
    if ( strlen( $hex ) === 3 ) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    if ( strlen( $hex ) !== 6 ) return '0, 0, 0';
    return implode( ', ', array_map( 'hexdec', str_split( $hex, 2 ) ) );
}

/**
 * Darken a hex colour by a fractional amount (0–1).
 * Returns a hex string. Falls back to original on bad input.
 */
function lsg_darken_hex( string $hex, float $amount ) : string {
    $hex = ltrim( $hex, '#' );
    if ( strlen( $hex ) === 3 ) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    if ( strlen( $hex ) !== 6 ) return '#' . $hex;
    [$r, $g, $b] = array_map( 'hexdec', str_split( $hex, 2 ) );
    $r = max( 0, (int) round( $r * ( 1 - $amount ) ) );
    $g = max( 0, (int) round( $g * ( 1 - $amount ) ) );
    $b = max( 0, (int) round( $b * ( 1 - $amount ) ) );
    return sprintf( '#%02x%02x%02x', $r, $g, $b );
}
