<?php
/**
 * ACM site modals (flight, maintenance, management, career, Starlink) with theme_strings.
 *
 * @package Leadwerk_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy page URL for modal consent links.
 *
 * @param string|null $lang Language code.
 * @return string
 */
function leadwerk_theme_modal_privacy_url( $lang = null ) {
	$lang = $lang ?: leadwerk_theme_get_current_lang();
	$url  = leadwerk_theme_get_page_url( 'acm-datenschutz-v1', $lang, '' );
	return '' !== trim( $url ) ? $url : '#';
}

/**
 * Markup for modal privacy checkbox label (HTML with link).
 *
 * @param string|null $lang Language code.
 * @return string
 */
function leadwerk_theme_modal_privacy_label_html( $lang = null ) {
	$lang       = $lang ?: leadwerk_theme_get_current_lang();
	$prefix     = leadwerk_theme_get_string( 'modal_consent_prefix', '', $lang );
	$link_label = leadwerk_theme_get_string( 'modal_consent_link_label', '', $lang );
	$suffix     = leadwerk_theme_get_string( 'modal_consent_suffix', '', $lang );
	$href       = esc_url( leadwerk_theme_modal_privacy_url( $lang ) );
	return sprintf(
		'%s<a class="text-accent hover:underline" href="%s">%s</a>%s',
		esc_html( $prefix ),
		$href,
		esc_html( $link_label ),
		esc_html( $suffix )
	);
}

/**
 * Full modals HTML (flight, maintenance, management, career, Starlink).
 *
 * @param string|null $lang Language code.
 * @return string
 */
function leadwerk_theme_get_acm_modals_markup( $lang = null ) {
	$lang = $lang ?: leadwerk_theme_get_current_lang();
	$s    = static function ( $key, $fb = '' ) use ( $lang ) {
		return leadwerk_theme_get_string( $key, $fb, $lang );
	};

	$close_aria = $s( 'ui_close_label', 'Close' );
	$stub_raw   = $s( 'modal_form_stub_message', 'Form stub.' );
	$privacy    = leadwerk_theme_modal_privacy_label_html( $lang );
	$star_cta   = esc_url( leadwerk_theme_get_page_url( 'acm-contact-v1', $lang, '#' ) . '#maintenance' );

	$opt = static function ( $option_name, $string_key, $fallback_default = '' ) use ( $s ) {
		$v = leadwerk_theme_get_option_value( $option_name, '' );
		return '' !== trim( $v ) ? $v : $s( $string_key, $fallback_default );
	};

	$starlink_image_url = leadwerk_theme_get_option_image_url(
		'starlink_modal_image',
		'Fotos/starlink-popup.png'
	);

	$cta_url_override = trim( (string) leadwerk_theme_get_option_value( 'starlink_modal_cta_url', '' ) );
	if ( '' !== $cta_url_override ) {
		$star_cta = esc_url( $cta_url_override );
	}

	ob_start();
	?>
<!-- Flight Request Modal -->
<div aria-labelledby="flight-modal-title" aria-modal="true" class="fixed inset-0 z-[100] hidden" id="flight-modal" role="dialog">
<div class="modal-backdrop absolute inset-0" onclick="closeModal('flight-modal')"></div>
<div class="modal-container">
<div class="modal-content" id="flight-modal-content">
<div class="modal-header">
<h2 class="modal-title" id="flight-modal-title"><?php echo esc_html( $s( 'modal_flight_title', 'Flug anfragen' ) ); ?></h2>
<button type="button" aria-label="<?php echo esc_attr( $close_aria ); ?>" class="modal-close" onclick="closeModal('flight-modal')">
<svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M6 18L18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg>
</button>
</div>
<div class="modal-body">
<form class="space-y-4" onsubmit="event.preventDefault(); alert('<?php echo esc_js( $stub_raw ); ?>');">
<div class="grid sm:grid-cols-2 gap-4">
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="flight-from"><?php echo esc_html( $s( 'modal_flight_from_label', 'Von *' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="flight-from" placeholder="<?php echo esc_attr( $s( 'modal_flight_from_placeholder', '' ) ); ?>" required type="text"/>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="flight-to"><?php echo esc_html( $s( 'modal_flight_to_label', 'Nach *' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="flight-to" placeholder="<?php echo esc_attr( $s( 'modal_flight_to_placeholder', '' ) ); ?>" required type="text"/>
</div>
</div>
<div class="grid sm:grid-cols-2 gap-4">
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="flight-date"><?php echo esc_html( $s( 'modal_flight_date_label', 'Datum *' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="flight-date" required type="date"/>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="flight-pax"><?php echo esc_html( $s( 'modal_flight_pax_label', 'Passagiere *' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="flight-pax" max="19" min="1" placeholder="<?php echo esc_attr( $s( 'modal_flight_pax_placeholder', '' ) ); ?>" required type="number"/>
</div>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="flight-email"><?php echo esc_html( $s( 'modal_flight_email_label', 'E-Mail *' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="flight-email" placeholder="<?php echo esc_attr( $s( 'modal_flight_email_placeholder', '' ) ); ?>" required type="email"/>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="flight-phone"><?php echo esc_html( $s( 'modal_flight_phone_label', 'Telefon' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="flight-phone" placeholder="<?php echo esc_attr( $s( 'modal_flight_phone_placeholder', '' ) ); ?>" type="tel"/>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="flight-message"><?php echo esc_html( $s( 'modal_flight_message_label', 'Nachricht' ) ); ?></label>
<textarea class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors resize-none" id="flight-message" placeholder="<?php echo esc_attr( $s( 'modal_flight_message_placeholder', '' ) ); ?>" rows="3"></textarea>
</div>
<div class="flex items-start gap-3">
<input class="mt-1 w-4 h-4 border-stone-300 rounded text-accent focus:ring-accent" id="flight-privacy" required type="checkbox"/>
<label class="text-sm text-stone-600" for="flight-privacy"><?php echo wp_kses_post( $privacy ); ?></label>
</div>
<button class="w-full btn-filled" type="submit"><?php echo esc_html( $s( 'modal_flight_submit', 'Anfrage senden' ) ); ?></button>
</form>
</div>
</div>
</div>
</div>
<!-- Maintenance Modal -->
<div aria-labelledby="maintenance-modal-title" aria-modal="true" class="fixed inset-0 z-[100] hidden" id="maintenance-modal" role="dialog">
<div class="modal-backdrop absolute inset-0" onclick="closeModal('maintenance-modal')"></div>
<div class="modal-container">
<div class="modal-content" id="maintenance-modal-content">
<div class="modal-header">
<h2 class="modal-title" id="maintenance-modal-title"><?php echo esc_html( $s( 'modal_maint_title', 'Maintenance Slot anfragen' ) ); ?></h2>
<button type="button" aria-label="<?php echo esc_attr( $close_aria ); ?>" class="modal-close" onclick="closeModal('maintenance-modal')">
<svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M6 18L18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg>
</button>
</div>
<div class="modal-body">
<form class="space-y-4" onsubmit="event.preventDefault(); alert('<?php echo esc_js( $stub_raw ); ?>');">
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="maint-aircraft"><?php echo esc_html( $s( 'modal_maint_aircraft_label', 'Flugzeugtyp *' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="maint-aircraft" placeholder="<?php echo esc_attr( $s( 'modal_maint_aircraft_placeholder', '' ) ); ?>" required type="text"/>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="maint-reg"><?php echo esc_html( $s( 'modal_maint_reg_label', 'Registrierung' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="maint-reg" placeholder="<?php echo esc_attr( $s( 'modal_maint_reg_placeholder', '' ) ); ?>" type="text"/>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="maint-type"><?php echo esc_html( $s( 'modal_maint_type_label', 'Art der Wartung *' ) ); ?></label>
<select class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors bg-white" id="maint-type" required>
<option value=""><?php echo esc_html( $s( 'modal_maint_type_placeholder', 'Bitte wählen...' ) ); ?></option>
<option value="line"><?php echo esc_html( $s( 'modal_maint_opt_line', 'Line Maintenance' ) ); ?></option>
<option value="base"><?php echo esc_html( $s( 'modal_maint_opt_base', 'Base Maintenance' ) ); ?></option>
<option value="avionics"><?php echo esc_html( $s( 'modal_maint_opt_avionics', 'Avionik' ) ); ?></option>
<option value="cabin"><?php echo esc_html( $s( 'modal_maint_opt_cabin', 'Cabin Refurbishment' ) ); ?></option>
<option value="aog"><?php echo esc_html( $s( 'modal_maint_opt_aog', 'AOG Support' ) ); ?></option>
<option value="other"><?php echo esc_html( $s( 'modal_maint_opt_other', 'Sonstiges' ) ); ?></option>
</select>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="maint-date"><?php echo esc_html( $s( 'modal_maint_date_label', 'Gewünschter Zeitraum *' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="maint-date" placeholder="<?php echo esc_attr( $s( 'modal_maint_date_placeholder', '' ) ); ?>" required type="text"/>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="maint-email"><?php echo esc_html( $s( 'modal_maint_email_label', 'E-Mail *' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="maint-email" placeholder="<?php echo esc_attr( $s( 'modal_maint_email_placeholder', '' ) ); ?>" required type="email"/>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="maint-message"><?php echo esc_html( $s( 'modal_maint_message_label', 'Details zur Anfrage' ) ); ?></label>
<textarea class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors resize-none" id="maint-message" placeholder="<?php echo esc_attr( $s( 'modal_maint_message_placeholder', '' ) ); ?>" rows="3"></textarea>
</div>
<div class="flex items-start gap-3">
<input class="mt-1 w-4 h-4 border-stone-300 rounded text-accent focus:ring-accent" id="maint-privacy" required type="checkbox"/>
<label class="text-sm text-stone-600" for="maint-privacy"><?php echo wp_kses_post( $privacy ); ?></label>
</div>
<button class="w-full btn-filled" type="submit"><?php echo esc_html( $s( 'modal_maint_submit', 'Slot anfragen' ) ); ?></button>
</form>
</div>
</div>
</div>
</div>
<!-- Management Modal -->
<div aria-labelledby="management-modal-title" aria-modal="true" class="fixed inset-0 z-[100] hidden" id="management-modal" role="dialog">
<div class="modal-backdrop absolute inset-0" onclick="closeModal('management-modal')"></div>
<div class="modal-container">
<div class="modal-content" id="management-modal-content">
<div class="modal-header">
<h2 class="modal-title" id="management-modal-title"><?php echo esc_html( $s( 'modal_mgmt_title', 'Management-Gespräch' ) ); ?></h2>
<button type="button" aria-label="<?php echo esc_attr( $close_aria ); ?>" class="modal-close" onclick="closeModal('management-modal')">
<svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M6 18L18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg>
</button>
</div>
<div class="modal-body">
<form class="space-y-4" onsubmit="event.preventDefault(); alert('<?php echo esc_js( $stub_raw ); ?>');">
<div class="grid sm:grid-cols-2 gap-4">
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="mgmt-name"><?php echo esc_html( $s( 'modal_mgmt_name_label', 'Name *' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="mgmt-name" placeholder="<?php echo esc_attr( $s( 'modal_mgmt_name_placeholder', '' ) ); ?>" required type="text"/>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="mgmt-company"><?php echo esc_html( $s( 'modal_mgmt_company_label', 'Unternehmen' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="mgmt-company" placeholder="<?php echo esc_attr( $s( 'modal_mgmt_company_placeholder', '' ) ); ?>" type="text"/>
</div>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="mgmt-aircraft"><?php echo esc_html( $s( 'modal_mgmt_aircraft_label', 'Flugzeugtyp' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="mgmt-aircraft" placeholder="<?php echo esc_attr( $s( 'modal_mgmt_aircraft_placeholder', '' ) ); ?>" type="text"/>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="mgmt-email"><?php echo esc_html( $s( 'modal_mgmt_email_label', 'E-Mail *' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="mgmt-email" placeholder="<?php echo esc_attr( $s( 'modal_mgmt_email_placeholder', '' ) ); ?>" required type="email"/>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="mgmt-phone"><?php echo esc_html( $s( 'modal_mgmt_phone_label', 'Telefon *' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="mgmt-phone" placeholder="<?php echo esc_attr( $s( 'modal_mgmt_phone_placeholder', '' ) ); ?>" required type="tel"/>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="mgmt-message"><?php echo esc_html( $s( 'modal_mgmt_message_label', 'Ihre Anfrage' ) ); ?></label>
<textarea class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors resize-none" id="mgmt-message" placeholder="<?php echo esc_attr( $s( 'modal_mgmt_message_placeholder', '' ) ); ?>" rows="3"></textarea>
</div>
<div class="flex items-start gap-3">
<input class="mt-1 w-4 h-4 border-stone-300 rounded text-accent focus:ring-accent" id="mgmt-privacy" required type="checkbox"/>
<label class="text-sm text-stone-600" for="mgmt-privacy"><?php echo wp_kses_post( $privacy ); ?></label>
</div>
<button class="w-full btn-filled" type="submit"><?php echo esc_html( $s( 'modal_mgmt_submit', 'Gespräch vereinbaren' ) ); ?></button>
</form>
</div>
</div>
</div>
</div>
<!-- Career Modal -->
<div aria-labelledby="career-modal-title" aria-modal="true" class="fixed inset-0 z-[100] hidden" id="career-modal" role="dialog">
<div class="modal-backdrop absolute inset-0" onclick="closeModal('career-modal')"></div>
<div class="modal-container">
<div class="modal-content" id="career-modal-content">
<div class="modal-header">
<h2 class="modal-title" id="career-modal-title"><?php echo esc_html( $s( 'modal_career_title', 'Online Bewerbung' ) ); ?></h2>
<button type="button" aria-label="<?php echo esc_attr( $close_aria ); ?>" class="modal-close" onclick="closeModal('career-modal')">
<svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M6 18L18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg>
</button>
</div>
<div class="modal-body">
<form class="space-y-4" onsubmit="event.preventDefault(); alert('<?php echo esc_js( $stub_raw ); ?>');">
<div class="grid sm:grid-cols-2 gap-4">
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="career-firstname"><?php echo esc_html( $s( 'modal_career_firstname_label', 'Vorname *' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="career-firstname" required type="text"/>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="career-lastname"><?php echo esc_html( $s( 'modal_career_lastname_label', 'Nachname *' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="career-lastname" required type="text"/>
</div>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="career-email"><?php echo esc_html( $s( 'modal_career_email_label', 'E-Mail *' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="career-email" placeholder="<?php echo esc_attr( $s( 'modal_career_email_placeholder', '' ) ); ?>" required type="email"/>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="career-phone"><?php echo esc_html( $s( 'modal_career_phone_label', 'Telefon' ) ); ?></label>
<input class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors" id="career-phone" placeholder="<?php echo esc_attr( $s( 'modal_career_phone_placeholder', '' ) ); ?>" type="tel"/>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="career-position"><?php echo esc_html( $s( 'modal_career_position_label', 'Gewünschte Position *' ) ); ?></label>
<select class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors bg-white" id="career-position" required>
<option value=""><?php echo esc_html( $s( 'modal_career_position_placeholder', 'Bitte wählen...' ) ); ?></option>
<option value="mechanic"><?php echo esc_html( $s( 'modal_career_opt_mechanic', '' ) ); ?></option>
<option value="avionics"><?php echo esc_html( $s( 'modal_career_opt_avionics', '' ) ); ?></option>
<option value="engineer"><?php echo esc_html( $s( 'modal_career_opt_engineer', '' ) ); ?></option>
<option value="pilot"><?php echo esc_html( $s( 'modal_career_opt_pilot', '' ) ); ?></option>
<option value="ops"><?php echo esc_html( $s( 'modal_career_opt_ops', '' ) ); ?></option>
<option value="other"><?php echo esc_html( $s( 'modal_career_opt_other', '' ) ); ?></option>
</select>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1" for="career-message"><?php echo esc_html( $s( 'modal_career_message_label', 'Anschreiben / Motivation' ) ); ?></label>
<textarea class="w-full px-4 py-2.5 border border-stone-300 rounded focus:ring-2 focus:ring-accent focus:border-accent outline-none transition-colors resize-none" id="career-message" placeholder="<?php echo esc_attr( $s( 'modal_career_message_placeholder', '' ) ); ?>" rows="4"></textarea>
</div>
<div>
<label class="block text-sm font-medium text-stone-700 mb-1"><?php echo esc_html( $s( 'modal_career_cv_label', 'Lebenslauf (PDF)' ) ); ?></label>
<div class="border-2 border-dashed border-stone-300 rounded-lg p-4 text-center hover:border-stone-400 transition-colors cursor-pointer">
<input accept=".pdf" class="hidden" id="career-cv" type="file"/>
<label class="cursor-pointer" for="career-cv">
<svg class="w-8 h-8 mx-auto text-stone-400 mb-2" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"></path>
</svg>
<span class="text-sm text-stone-600"><?php echo esc_html( $s( 'modal_career_cv_choose', 'PDF-Datei auswählen' ) ); ?></span>
</label>
</div>
</div>
<div class="flex items-start gap-3">
<input class="mt-1 w-4 h-4 border-stone-300 rounded text-accent focus:ring-accent" id="career-privacy" required type="checkbox"/>
<label class="text-sm text-stone-600" for="career-privacy"><?php echo wp_kses_post( $privacy ); ?></label>
</div>
<button class="w-full btn-filled" type="submit"><?php echo esc_html( $s( 'modal_career_submit', 'Bewerbung absenden' ) ); ?></button>
</form>
</div>
</div>
</div>
</div>
<!-- Starlink Authorized Reseller Pop-up -->
<div aria-labelledby="starlink-modal-title" aria-modal="true" class="fixed inset-0 z-[100] hidden" id="starlink-modal" role="dialog">
<div class="modal-backdrop absolute inset-0" onclick="closeModal('starlink-modal')"></div>
<div class="modal-container">
<div class="modal-content starlink-popup-content" id="starlink-modal-content">
<div class="modal-header">
<h2 class="modal-title" id="starlink-modal-title"><?php echo esc_html( $opt( 'starlink_modal_title', 'starlink_modal_title', '' ) ); ?></h2>
<button type="button" aria-label="<?php echo esc_attr( $close_aria ); ?>" class="modal-close" onclick="closeModal('starlink-modal')">
<svg class="w-5 h-5" fill="none" stroke="currentColor" viewbox="0 0 24 24">
<path d="M6 18L18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
</svg>
</button>
</div>
<div class="starlink-popup-image">
<img alt="<?php echo esc_attr( $opt( 'starlink_modal_image_alt', 'starlink_image_alt', '' ) ); ?>" height="280" loading="lazy" src="<?php echo esc_url( $starlink_image_url ); ?>" width="400"/>
</div>
<div class="starlink-popup-body modal-body">
<p class="starlink-badge"><?php echo esc_html( $opt( 'starlink_modal_badge', 'starlink_badge', '' ) ); ?></p>
<p class="starlink-headline"><?php echo esc_html( $opt( 'starlink_modal_headline', 'starlink_headline', '' ) ); ?></p>
<p class="starlink-teaser"><?php echo esc_html( $opt( 'starlink_modal_teaser', 'starlink_teaser', '' ) ); ?></p>
<a class="starlink-cta" href="<?php echo esc_url( $star_cta ); ?>"><?php echo esc_html( $opt( 'starlink_modal_cta_label', 'starlink_cta_label', '' ) ); ?></a>
</div>
</div>
</div>
</div>
	<?php
	return (string) ob_get_clean();
}
