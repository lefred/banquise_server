<?php if ($canManageCatalogSource): ?>
<article class="admin-panel card">
  <h3>Agent catalogs</h3>
  <p>Define signed catalogs here, then assign them from each server's detail page. Public keys must also be provisioned locally on the agents.</p>
  <?php foreach ($app->fleetRepositories() as $repo): ?>
  <details>
    <summary><?=h($repo['name'])?> · <?=h($repo['key_id'])?></summary>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?=csrf()?>">
      <input type="hidden" name="form" value="fleet_repository_save">
      <input type="hidden" name="name" value="<?=h($repo['name'])?>">
      <label>Catalog URL<input name="url" type="url" value="<?=h($repo['url'])?>" required></label>
      <label>Minisign public key<textarea name="public_key" required><?=h($repo['public_key'])?></textarea></label>
      <small>Last verified: <?=h($repo['synced_at'])?>. Saving verifies and refreshes the catalog and cancels its pending tasks.</small>
      <button>Verify and save</button>
    </form>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=csrf()?>">
      <input type="hidden" name="form" value="fleet_repository_delete">
      <input type="hidden" name="name" value="<?=h($repo['name'])?>">
      <button class="secondary">Remove catalog and its assignments</button>
    </form>
  </details>
  <?php endforeach; ?>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=csrf()?>">
    <input type="hidden" name="form" value="fleet_repository_save">
    <label>Repository name<input name="name" pattern="[A-Za-z0-9_.\-]{1,128}" maxlength="128" required placeholder="community"></label>
    <label>Catalog URL<input name="url" type="url" required placeholder="https://example.org/catalog.json"></label>
    <label>Minisign public key<textarea name="public_key" required placeholder="Paste the contents of the publisher's .pub file"></textarea></label>
    <button>Verify and add catalog</button>
  </form>
</article>
<?php endif; ?>
