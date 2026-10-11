import { test, expect } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';

test('Task 14: typed confirmation, cancellation and server rejection protect disposable full-capture data', async ({page}, info) => {
 test.setTimeout(90000);
 runWordPressFixture(`<?php
  if (DB_NAME !== 'tests-wordpress') throw new RuntimeException('Disposable DB required');
  \\SlimStat\\Heatmap\\Store::setup(); global $wpdb;
  foreach (['slim_heatmap','slim_heatmap_elements'] as $suffix) {
   $table=$wpdb->prefix.$suffix; $backup=$table.'_october_backup';
   if ($wpdb->get_var("SHOW TABLES LIKE '$backup'")) throw new RuntimeException('Unrestored fixture backup');
   $wpdb->query("CREATE TABLE $backup LIKE $table"); $wpdb->query("INSERT INTO $backup SELECT * FROM $table");
  }
  $wpdb->insert($wpdb->prefix.'slim_stats',['resource'=>'/october-delete/','dt'=>time()]);
  $id=(int)$wpdb->insert_id; update_option('october_heatmap_delete_stat',$id,false);
  $wpdb->insert($wpdb->prefix.'slim_heatmap',['id'=>$id,'page'=>'12345678','dt'=>time()]);
  $wpdb->insert($wpdb->prefix.'slim_heatmap_elements',['sel'=>'12345678','selector'=>'body','label'=>'fixture']);
 `);
 const counts=()=>JSON.parse(runWordPressFixture(`<?php global $wpdb; $c=[]; foreach (['slim_heatmap','slim_heatmap_elements','slim_stats','slim_events'] as $s) $c[$s]=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.$s); echo wp_json_encode($c);`));
 try {
  const before=counts(); expect(before.slim_heatmap).toBeGreaterThan(0);
  await page.goto('/wp-admin/admin.php?page=slimheatmap');
  await page.getByRole('button',{name:'Delete heatmap data',exact:true}).click();
  const dialog=page.locator('#ss-hm-delete');
  const submit=dialog.locator('[data-confirm-delete]');
  await expect(submit).toBeDisabled(); await dialog.locator('input[name=heatmap_delete_confirmation]').fill('delete'); await expect(submit).toBeDisabled();
  await page.keyboard.press('Escape'); await expect(dialog).toBeHidden(); expect(counts()).toEqual(before);
  const nonce=(await dialog.locator('[name=_wpnonce]').inputValue());
  const rejected=await page.request.post('/wp-admin/admin-post.php',{form:{action:'slimstat_heatmap_delete',_wpnonce:nonce,heatmap_delete_confirmation:'delete'}});
  expect(rejected.status()).toBe(400); expect(counts()).toEqual(before);
  for (const value of ['<b>DELETE</b>', ' DELETE ', 'DELETE\\']) {
    const malformed=await page.request.post('/wp-admin/admin-post.php',{form:{action:'slimstat_heatmap_delete',_wpnonce:nonce,heatmap_delete_confirmation:value}});
    expect(malformed.status()).toBe(400);
  }
  const array=await page.request.post('/wp-admin/admin-post.php',{form:{action:'slimstat_heatmap_delete',_wpnonce:nonce,'heatmap_delete_confirmation[]':'DELETE'}});
  expect(array.status()).toBe(400);
  const badNonce=await page.request.post('/wp-admin/admin-post.php',{form:{action:'slimstat_heatmap_delete',_wpnonce:'invalid',heatmap_delete_confirmation:'DELETE'}});
  expect(badNonce.status()).toBe(403); expect(counts()).toEqual(before);
  await page.getByRole('button',{name:'Delete heatmap data',exact:true}).click();
  await dialog.locator('input[name=heatmap_delete_confirmation]').fill('DELETE'); await expect(submit).toBeEnabled();
  await submit.click(); await expect(page.locator('.ss-hm-card[role=status]')).toContainText('Heatmap data deleted');
  const after=counts(); expect(after).toEqual({...before,slim_heatmap:0,slim_heatmap_elements:0});
  await info.attach('delete-scope.json',{body:JSON.stringify({before,after}),contentType:'application/json'});
 } finally {
  runWordPressFixture(`<?php global $wpdb;
   foreach (['slim_heatmap','slim_heatmap_elements'] as $s) {
    $t=$wpdb->prefix.$s; $b=$t.'_october_backup'; $wpdb->query("TRUNCATE TABLE $t"); $wpdb->query("INSERT INTO $t SELECT * FROM $b"); $wpdb->query("DROP TABLE $b");
   }
   $wpdb->delete($wpdb->prefix.'slim_stats',['id'=>get_option('october_heatmap_delete_stat')]); delete_option('october_heatmap_delete_stat'); \\SlimStat\\Heatmap\\Store::invalidate();
  `);
 }
});
