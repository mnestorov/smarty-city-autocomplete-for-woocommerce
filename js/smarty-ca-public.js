jQuery(function($) {
    const $postcode = $('input[name="billing_postcode"]');
    const $select = $('select[name="billing_city"]');

    if ($select.length) {
        $select.select2({
            placeholder: "Start typing city name...",
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
                            return {
                                id: item.city,
                                text: item.city,
                                postal_code: item.postal_code
                            };
                        })
                    };
                }
            }
        });

        $select.on('select2:select', function(e) {
            const selected = e.params.data;
            if (selected && selected.postal_code) {
                $postcode.val(selected.postal_code).trigger('change');
            }
        });
    }
});
