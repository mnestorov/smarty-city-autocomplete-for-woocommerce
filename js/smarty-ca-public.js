jQuery(function($) {
    const $select = $('select[name="billing_city"]');
    const $postcode = $('input[name="billing_postcode"]');

    if (!$select.length) return;

    $select.select2({
        placeholder: "",
        minimumInputLength: 2,
        width: '100%',
        ajax: {
            url: smartyCityAjax.ajax_url,
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return {
                    action: 'smarty_get_city_suggestions',
                    term: params.term,
                    country: smartyCityAjax.country
                };
            },
            processResults: function (data) {
                return {
                    results: data.map(function (item) {
                        const cityOnly = item.city.split(' / ')[0].trim();
                        return {
                            id: item.city,
                            text: `${cityOnly} [${item.postal_code}]`,
                            city: cityOnly,
                            postal_code: item.postal_code
                        };
                    })
                };
            }
        },
        templateSelection: function (data) {
            return data.text || data.id;
        },
        templateResult: function (data) {
            return data.text || data.id;
        }
    });

    $select.on('select2:select', function(e) {
        const selected = e.params.data;
        if (selected && selected.postal_code) {
            $('input[name="billing_postcode"]').val(selected.postal_code).trigger('change');
        }
    });
});
