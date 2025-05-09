jQuery(function($) {
    let cache = {};
    let $input = $('#smarty-autocomplete-city');
    let $postcode = $('input[name="billing_postcode"]');

    if (!$input.length) return;

    // Initialize jQuery UI Autocomplete
    $input.autocomplete({
        minLength: 2,
        delay: 150,
        source: function(request, response) {
            const term = request.term.toLowerCase();

            if (cache[term]) {
                response(cache[term].map(c => c.city));
                return;
            }

            $.get(smartyCityAjax.ajax_url, {
                action: 'smarty_get_city_suggestions',
                term: term,
                country: smartyCityAjax.country
            }, function(data) {
                cache[term] = data;
                response(data.map(c => c.city));
            });
        },
        select: function(e, ui) {
            const selected = cache[$input.val().toLowerCase()]?.find(c => c.city === ui.item.value);

            if (selected) {
                $postcode.val(selected.postal_code).trigger('change');
            }
        }
    });

    // Clear postcode if city is changed manually
    $input.on('change blur', function() {
        const current = $input.val();
        const match = Object.values(cache).flat().find(c => c.city === current);
        if (!match) {
            $postcode.val('').trigger('change');
        }
    });
});
