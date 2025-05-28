jQuery(function ($) {

	/**
	 * Initialise Select2 autocomplete for either billing or shipping fields.
	 *
	 * @param {"billing"|"shipping"} prefix  Field namespace used by WooCommerce.
	 */
	const initCityAutocomplete = (prefix) => {

		const $city      = $(`select[name="${prefix}_city"]`);
		const $postcode  = $(`input[name="${prefix}_postcode"]`);
		const $state     = $(`select[name="${prefix}_state"], input[name="${prefix}_state"]`);

		if (!$city.length) { // checkout might hide shipping
			return;
		}

		$city.select2({
			placeholder: $city.data('placeholder'),
			minimumInputLength: 2,
			allowClear: false,
			width: '100%',
			language: {
				inputTooShort: () => smartyCityAjax.inputTooShort,
				searching:     () => smartyCityAjax.searching,
				noResults:     () => smartyCityAjax.noResults,
				loadingMore:   () => smartyCityAjax.loadingMore
			},
			ajax: {
				url: smartyCityAjax.ajaxUrl,
				dataType: 'json',
				delay: 250,
				data: params => ({
					action:  'smarty_get_city_suggestions',
					term:    params.term,
					country: smartyCityAjax.country
				}),
				processResults: data => ({
					results: data.map(item => {
						const cityOnly = item.city.split(' / ')[0].trim();
						return {
							id:          item.city,
							text:        `${cityOnly} [${item.postal_code}]`,
							city:        cityOnly,
							postal_code: item.postal_code,
							state_code:  item.state_code || ''
						};
					})
				})
			},
			templateSelection: d => d.text || d.id,
			templateResult:    d => d.text || d.id
		});

		$city.on('select2:select', (e) => {
            console.log('SELECTED →', e.params.data);
			const sel = e.params.data;

			if (sel.postal_code) {
				$postcode.val(sel.postal_code).trigger('change');
			}

			if (sel.state_code) {
				// <select> needs an <option>, <input> is fine with plain text.
				if ($state.is('select') &&
					!$state.find(`option[value="${sel.state_code}"]`).length) {
					$state.append(
						`<option value="${sel.state_code}">${sel.state_code}</option>`
					);
				}
				$state.val(sel.state_code).trigger('change');
			}
		});
	};

	// ---------- Kick it off for both namespaces ----------
	initCityAutocomplete('billing');
	initCityAutocomplete('shipping');
});
