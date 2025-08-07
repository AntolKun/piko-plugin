<div class="wrap">
  <h1>SERP Scraper</h1>

  <?php if (empty(get_option('piko_serpapi_key'))): ?>
    <div class="notice notice-error">
      <p>Admin belum mengkonfigurasi API key. Silakan hubungi administrator.</p>
    </div>
  <?php else: ?>
    <div class="card">
      <h2>Google Search Scraper</h2>
      <div class="serp-form">
        <input type="text" id="piko-user-keyword" placeholder="Masukkan keyword..." class="regular-text">
        <button id="piko-user-scrape-btn" class="button button-primary">Search</button>
      </div>

      <div id="piko-user-results" style="margin-top: 20px;"></div>
    </div>

    <script>
      jQuery(document).ready(function($) {
        $('#piko-user-scrape-btn').on('click', function() {
          const keyword = $('#piko-user-keyword').val();
          if (!keyword) return;

          $(this).prop('disabled', true).text('Searching...');

          $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: {
              action: 'piko_user_scrape',
              keyword: keyword,
              security: '<?php echo wp_create_nonce("piko_user_scrape_nonce"); ?>'
            },
            success: function(response) {
              if (response.success) {
                $('#piko-user-results').html(response.data);
              } else {
                alert('Error: ' + response.data);
              }
            },
            complete: function() {
              $('#piko-user-scrape-btn').prop('disabled', false).text('Search');
            }
          });
        });
      });
    </script>
  <?php endif; ?>
</div>