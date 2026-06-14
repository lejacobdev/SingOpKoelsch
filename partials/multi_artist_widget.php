<?php
/**
 * Renders a multi-artist tag-based select widget.
 *
 * @param string $fieldName  HTML field name (e.g. "band_id")
 * @param int[]  $selectedIds Already-selected band IDs
 * @param array  $bands       All bands [['band_id'=>int,'band_name'=>string],...]
 * @param string $placeholder Search input placeholder text
 */
function renderMultiArtistSelect(string $fieldName, array $selectedIds, array $bands, string $placeholder = ''): void {
    static $counter = 0;
    $counter++;
    $uid = 'mas' . $counter;

    $bandsJson    = json_encode(array_values(array_map(fn($b) => ['id' => (int)$b['band_id'], 'name' => $b['band_name']], $bands)), JSON_UNESCAPED_UNICODE);
    $selectedJson = json_encode(array_values(array_map('intval', $selectedIds)));
    $ph = htmlspecialchars($placeholder ?: t('modal.search_artist_ph'));
    ?>
    <div class="multi-artist-widget" id="<?= $uid ?>-wrap"
         data-field="<?= htmlspecialchars($fieldName) ?>"
         data-bands="<?= htmlspecialchars($bandsJson) ?>"
         data-selected="<?= htmlspecialchars($selectedJson) ?>">
      <div class="mas-tags" id="<?= $uid ?>-tags"></div>
      <div class="mas-input-row" style="position:relative;">
        <input type="text" class="mas-search" id="<?= $uid ?>-search"
               placeholder="<?= $ph ?>" autocomplete="off">
        <div class="mas-dropdown" id="<?= $uid ?>-dropdown" hidden></div>
      </div>
      <div class="mas-hidden" id="<?= $uid ?>-hidden"></div>
    </div>
    <script>
    (function(uid){
      if(typeof window.initMasWidget==='function') window.initMasWidget(uid);
      else { window._masQueue = window._masQueue||[]; window._masQueue.push(uid); }
    })('<?= $uid ?>');
    </script>
    <?php
}
?>
