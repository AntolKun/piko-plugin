<div class="wrap">
  <h1>SERP Scraper</h1>

  <div class="card">
    <h2>Scrape Google Results</h2>
    <div class="serp-form">
      <input type="text" id="piko-serp-keyword" placeholder="Masukkan keyword..." class="regular-text">
      <button id="piko-scrape-btn" class="button button-primary">Scrape</button>
    </div>

    <div id="piko-results-container" style="margin-top: 20px; display: none;">
      <h3>Hasil Scraping:</h3>
      <div id="piko-results-list"></div>
      <button id="piko-pick-random" class="button" style="margin-top: 10px; display: none;">
        Pilih URL Random
      </button>
    </div>
  </div>

  <div id="piko-selected-result" style="margin-top: 20px; display: none;">
    <h3>URL Terpilih:</h3>
    <div class="selected-url-card">
      <p><strong>Title:</strong> <span id="selected-title"></span></p>
      <p><strong>URL:</strong> <span id="selected-url"></span></p>
      <p><strong>Snippet:</strong> <span id="selected-snippet"></span></p>
      <a id="selected-url-link" href="#" target="_blank" class="button">Buka Landing Page</a>
      <button id="piko-copy-url" class="button">Copy URL</button>
    </div>
  </div>
</div>

<script>
  jQuery(document).ready(function($) {
    $('#piko-scrape-btn').on('click', function() {
      const keyword = $('#piko-serp-keyword').val();
      if (!keyword) return;

      $(this).prop('disabled', true).text('Memproses...');

      $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
          action: 'piko_scrape_serp',
          keyword: keyword,
          security: '<?php echo wp_create_nonce("piko_serp_nonce"); ?>'
        },
        success: function(response) {
          if (response.success) {
            const results = response.data;
            let html = '<ul>';

            results.forEach((item, index) => {
              html += `<li>
                            <strong>${item.title}</strong><br>
                            <small>${item.url}</small><br>
                            <p>${item.snippet}</p>
                            <input type="hidden" class="serp-url" data-id="${index}" value="${item.url}">
                        </li>`;
            });

            html += '</ul>';
            $('#piko-results-list').html(html);
            $('#piko-results-container').show();
            $('#piko-pick-random').show();
          } else {
            alert('Error: ' + response.data);
          }
        },
        complete: function() {
          $('#piko-scrape-btn').prop('disabled', false).text('Scrape');
        }
      });
    });

    $('#piko-pick-random').on('click', function() {
      const urls = $('.serp-url');
      if (urls.length === 0) return;

      const randomIndex = Math.floor(Math.random() * urls.length);
      const selected = urls.eq(randomIndex);

      $('#selected-title').text(selected.closest('li').find('strong').text());
      $('#selected-url').text(selected.val());
      $('#selected-snippet').text(selected.closest('li').find('p').text());
      $('#selected-url-link').attr('href', selected.val());
      $('#piko-selected-result').show();

      // Simpan ke database
      $.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
          action: 'piko_track_click',
          url_id: selected.data('id'),
          security: '<?php echo wp_create_nonce("piko_serp_nonce"); ?>'
        }
      });
    });

    $('#piko-copy-url').on('click', function() {
      const url = $('#selected-url').text();
      navigator.clipboard.writeText(url);
      $(this).text('Copied!');
      setTimeout(() => $(this).text('Copy URL'), 2000);
    });
  });
</script>

<style>
  .serp-form {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
  }

  #piko-results-list ul {
    list-style: none;
    padding: 0;
  }

  #piko-results-list li {
    padding: 15px;
    margin-bottom: 10px;
    background: #f9f9f9;
    border-left: 4px solid #2271b1;
  }

  .selected-url-card {
    background: #f5f5f5;
    padding: 15px;
    border-radius: 4px;
  }
</style>