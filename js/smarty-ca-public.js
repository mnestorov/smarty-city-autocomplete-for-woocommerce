jQuery(function($) {
    let cache = {};

    $('#smarty-autocomplete-city').on('input', function() {
        const term = $(this).val();
        if (term.length < 2) return;

        if (cache[term]) {
            renderSuggestions(cache[term]);
            return;
        }

        $.get(smartyCityAjax.ajax_url, {
            action: 'smarty_get_city_suggestions',
            term: term,
            country: smartyCityAjax.country
        }, function(data) {
            cache[term] = data;
            renderSuggestions(data);
        });
    });

    function renderSuggestions(cities) {
        $('#smarty-autocomplete-city').autocomplete({
            source: cities.map(item => item.city),
            select: function(e, ui) {
                const selected = cities.find(c => c.city === ui.item.value);
                if (selected) {
                    $('input[name="billing_postcode"]').val(selected.postal_code);
                }
            }
        });
    }
});
