jQuery(document).ready(function($) {
  console.log("CiviCRM ADMIN JS script loaded");
      // Field mapping functionality
      $('#add-mapping').click(function() {
        const newRow = `
          <tr>
            <td><input type="text" name="wc_civicrm_field_mappings[new][wc]" class="regular-text"></td>
            <td><input type="text" name="wc_civicrm_field_mappings[new][civicrm]" class="regular-text"></td>
            <td><button type="button" class="button remove-mapping">Remove</button></td>
          </tr>`;
        $('#field-mappings-table tbody').append(newRow);
      });

      $(document).on('click', '.remove-mapping', function() {
        $(this).closest('tr').remove();
      });

      // Connection test
      $('#test-connection').click(function(e) {
        e.preventDefault();
        const $button = $(this);
        $button.prop('disabled', true).text('Testing...');
        
        $.post(ajaxurl, {
          action: 'wc_civicrm_test_connection',
          _ajax_nonce: $button.data('nonce')
        }, function(response) {
          if (response.success) {
            alert('Connection successful!');
          } else {
            alert('Connection failed: ' + response.data);
          }
          $button.prop('disabled', false).text('Test Connection');
        });
      });
    });
