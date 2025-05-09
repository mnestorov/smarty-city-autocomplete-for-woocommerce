jQuery(function($) {
    let cache = {};
    let lastResults = [];
    const $input = $('#smarty-autocomplete-city');
    const $postcode = $('input[name="billing_postcode"]');

    if (!$input.length) return;

    $input.autocomplete({
        minLength: 2,
        delay: 150,
        source: function(request, response) {
            const term = request.term.toLowerCase();

            if (cache[term]) {
                lastResults = cache[term];
                response(cache[term].map(c => c.city));
                return;
            }

            $.get(smartyCityAjax.ajax_url, {
                action: 'smarty_get_city_suggestions',
                term: term,
                country: smartyCityAjax.country
            }, function(data) {
                cache[term] = data;
                lastResults = data;
                response(data.map(c => c.city));
            });
        },
        select: function(e, ui) {
            const selected = lastResults.find(c => c.city === ui.item.value);
            if (selected) {
                $postcode.val(selected.postal_code).trigger('change');
            }
        }
    });

    $input.on('change blur', function() {
        const current = $input.val();
        const match = lastResults.find(c => c.city === current);
        if (!match) {
            $postcode.val('').trigger('change');
        }
    });
});
