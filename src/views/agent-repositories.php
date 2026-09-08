<?php if ($canManageFleet):
$assignedNames = array_column($app->agentRepositories($selectedUid), 'name');
$trustedIds = $app->agentKeyIds($selectedUid);
?>
<details class="card detail">
  <summary>Assigned catalogs (<?=count($assignedNames)?>)</summary>
  <p>Trusted key IDs reported by this agent: <?=h(implode(', ', $trustedIds) ?: 'None')?>.</p>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?=csrf()?>">
    <input type="hidden" name="form" value="agent_repositories">
    <input type="hidden" name="uid" value="<?=h($selectedUid)?>">
    <?php foreach ($app->fleetRepositories() as $repo): $trusted = in_array($repo['key_id'], $trustedIds, true); ?>
    <label class="mode-option">
      <input type="checkbox" name="catalogs[]" value="<?=h($repo['name'])?>" <?=in_array($repo['name'], $assignedNames, true) ? 'checked' : ''?> <?=$trusted ? '' : 'disabled'?>>
      <span><strong><?=h($repo['name'])?></strong><small><?=h($repo['url'])?> · <?=$trusted ? 'Key trusted' : 'Provision key '.$repo['key_id'].' on this agent first'?></small></span>
    </label>
    <?php endforeach; ?>
    <button>Save catalog assignments</button>
  </form>
</details>
<?php endif; ?>
