jQuery(document).ready(function($) {
  console.log("CiviCRM admin script loaded TEST");
    // Test CiviCRM Connection
    $('#test-civicrm-connection').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $resultContainer = $('#connection-test-result');
    
        $button.prop('disabled', true).addClass('loading');
        $resultContainer.empty();
    
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'test_civicrm_connection',
                nonce: wc_civicrm_admin_params.test_connection_nonce
            },
            success: function(response) {
                console.log('CiviCRM Connection Test Response:', response);
                if (response.success) {
                    $resultContainer.html(
                        '<div class="notice notice-success">' + 
                        '<p>' + response.data.message + '</p>' + 
                        (response.data.details ? 
                            '<pre>' + JSON.stringify(response.data.details, null, 2) + '</pre>' : 
                            '') + 
                        '</div>'
                    );
                } else {
                    $resultContainer.html(
                        '<div class="notice notice-error">' + 
                        '<p>' + response.data.message + '</p>' + 
                        '<p>Debug Info: ' + (response.data.debug_info || 'No additional info') + '</p>' + 
                        '</div>'
                    );
                }
            },
            error: function(xhr) {
                console.error('CiviCRM Connection Test Error:', xhr);
                $resultContainer.html(
                    '<div class="notice notice-error">' + 
                    '<p>An unexpected error occurred</p>' + 
                    '<pre>' + JSON.stringify(xhr.responseJSON, null, 2) + '</pre>' + 
                    '</div>'
                );
            },
            complete: function() {
                $button.prop('disabled', false).removeClass('loading');
            }
        });
    });
    // Test Contact Creation
    $('#test-contact-creation').on('click', function(e) {
    e.preventDefault();
    var $button = $(this);
    var $resultContainer = $('#contact-creation-test-result');

    $button.prop('disabled', true).addClass('loading');
    $resultContainer.empty();

    $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'test_contact_creation',
            nonce: wc_civicrm_admin_params.test_contact_creation_nonce
        },
        success: function(response) {
            console.log('CiviCRM Contact Creation Test Response:', response);
            if (response.success) {
                $resultContainer.html(
                    '<div class="notice notice-success">' + 
                    '<p>Test contact created successfully</p>' + 
                    '<pre>' + JSON.stringify(response.data.contact_details, null, 2) + '</pre>' + 
                    '</div>'
                );
            } else {
                $resultContainer.html(
                    '<div class="notice notice-error">' + 
                    '<p>' + response.data.message + '</p>' + 
                    '<p>Debug Info: ' + (response.data.debug_info || 'No additional info') + '</p>' + 
                    '</div>'
                );
            }
        },
        error: function(xhr) {
            console.error('CiviCRM Contact Creation Test Error:', xhr);
            $resultContainer.html(
                '<div class="notice notice-error">' + 
                '<p>An unexpected error occurred</p>' + 
                '<pre>' + JSON.stringify(xhr.responseJSON, null, 2) + '</pre>' + 
                '</div>'
            );
        },
        complete: function() {
            $button.prop('disabled', false).removeClass('loading');
        }
    });
});

$('#test-contribution-creation').on('click', function(e) {
    e.preventDefault();
    var $button = $(this);
    var $resultContainer = $('#contribution-creation-test-result');

    $button.prop('disabled', true).addClass('loading');
    $resultContainer.empty();

    $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'test_contribution_creation',
            nonce: wc_civicrm_admin_params.test_contribution_creation_nonce
        },
        success: function(response) {
            console.log('CiviCRM Contribution Creation Test Response:', response);
            if (response.success) {
                $resultContainer.html(
                    '<div class="notice notice-success">' + 
                    '<p>Test contribution created successfully</p>' + 
                    '<pre>' + JSON.stringify(response.data.contribution_details, null, 2) + '</pre>' + 
                    '</div>'
                );
            } else {
                $resultContainer.html(
                    '<div class="notice notice-error">' + 
                    '<p>' + response.data.message + '</p>' + 
                    '<p>Debug Info: ' + (response.data.debug_info || 'No additional info') + '</p>' + 
                    '</div>'
                );
            }
        },
        error: function(xhr) {
            console.error('CiviCRM Contribution Creation Test Error:', xhr);
            $resultContainer.html(
                '<div class="notice notice-error">' + 
                '<p>An unexpected error occurred</p>' + 
                '<pre>' + JSON.stringify(xhr.responseJSON, null, 2) + '</pre>' + 
                '</div>'
            );
        },
        complete: function() {
            $button.prop('disabled', false).removeClass('loading');
        }
    });
});

});
