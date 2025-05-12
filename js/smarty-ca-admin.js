jQuery(document).ready(function($) {
    // Handle tab switching
    $(".smarty-ca-nav-tab").click(function (e) {
        e.preventDefault();
        $(".smarty-ca-nav-tab").removeClass("smarty-ca-nav-tab-active");
        $(this).addClass("smarty-ca-nav-tab-active");

        $(".smarty-ca-tab-content").removeClass("active");
        $($(this).attr("href")).addClass("active");
    });

    // Load README.md
    $("#smarty-ca-load-readme-btn").click(function () {
        const $content = $("#smarty-ca-readme-content");
        $content.html("<p>Loading...</p>");

        $.ajax({
            url: smartyCityAutocomplete.ajaxUrl,
            type: "POST",
            data: {
                action: "smarty_ca_load_readme",
                nonce: smartyCityAutocomplete.nonce,
            },
            success: function (response) {
                console.log(response);
                if (response.success) {
                    $content.html(response.data);
                } else {
                    $content.html("<p>Error loading README.md</p>");
                }
            },
        });
    });

    // Load CHANGELOG.md
    $("#smarty-ca-load-changelog-btn").click(function () {
        const $content = $("#smarty-ca-changelog-content");
        $content.html("<p>Loading...</p>");

        $.ajax({
            url: smartyCityAutocomplete.ajaxUrl,
            type: "POST",
            data: {
                action: "smarty_ca_load_changelog",
                nonce: smartyCityAutocomplete.nonce,
            },
            success: function (response) {
                console.log(response);
                if (response.success) {
                    $content.html(response.data);
                } else {
                    $content.html("<p>Error loading CHANGELOG.md</p>");
                }
            },
        });
    });
});