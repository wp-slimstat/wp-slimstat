import { test, expect } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';
import { snapshotSlimstatOptions, restoreSlimstatOptions, closeDb } from './helpers/setup';

// Simulated stored-version upgrades run the real init/admin migration paths on disposable WordPress.
test('Tasks 17/28: stored settings survive core upgrades, repeats and Pro reactivation', async ({ page }, info) => {
  test.setTimeout(180000);
  await snapshotSlimstatOptions();
  const evidence: unknown[] = [];
  try {
    for (const version of ['5.4.0','5.4.5','5.5.0','6.0.0']) {
      runWordPressFixture(`<?php
        if (DB_NAME !== 'tests-wordpress') throw new RuntimeException('Disposable DB required');
        $s=wp_slimstat::init_options();
        $s=array_replace($s, ['version'=>'${version}', '_migration_5460'=>'0', 'is_tracking'=>'off', 'javascript_mode'=>'off', 'set_tracker_cookie'=>'off', 'anonymize_ip'=>'on', 'hash_ip'=>'on', 'gdpr_enabled'=>'off', 'use_slimstat_banner'=>'off', 'consent_integration'=>'', 'display_opt_out'=>'on', 'heatmap_capture'=>'off', 'heatmap_rate'=>0, 'heatmap_pages'=>'/private/*', 'rows_to_show'=>7, 'use_admin'=>false, 'auto_purge'=>0, 'auto_purge_delete'=>'no', 'delete_data_on_uninstall'=>'no', 'addon_email_report_email'=>'qa@example.invalid', 'addon_email_report_frequency'=>'daily', 'addon_email_report_list'=>'slim_p1_01', 'addon_email_report_attach_csv'=>'off', 'addon_email_report_send_authors'=>'off', 'addon_email_report_filters'=>'country equals us', 'addon_email_report_custom_mail_text'=>'Fixture', 'slimstat_pro_license_status'=>false, 'custom_extension_option'=>['disabled'=>false,'zero'=>0,'empty'=>'']]);
        update_option('slimstat_options',$s);
        update_option('october_upgrade_expected',$s,false);
      `);
      for (const pass of [1,2]) {
        await page.goto('/wp-admin/admin.php?page=slimconfig');
        const result=JSON.parse(runWordPressFixture(`<?php
          $expected=get_option('october_upgrade_expected'); $actual=get_option('slimstat_options'); $diff=[];
          foreach ($expected as $key=>$value) {
            if (in_array($key,['version','_migration_5460','browscap_last_modified'],true)) continue;
            if (!array_key_exists($key,$actual) || $actual[$key] !== $value) $diff[]=$key;
          }
          echo wp_json_encode(['version'=>'${version}','pass'=>${pass},'keys'=>count($expected)-3,'changed_keys'=>$diff,'effective_capture'=>wp_slimstat::$settings['heatmap_capture']]);
        `));
        evidence.push(result); expect(result.changed_keys).toEqual([]); expect(result.effective_capture).toBe('off');
      }
    }
    runWordPressFixture(`<?php
      require_once ABSPATH.'wp-admin/includes/plugin.php';
      deactivate_plugins('wp-slimstat-pro/wp-slimstat-pro.php',true);
      $s=get_option('slimstat_options'); unset($s['heatmap_capture']); update_option('slimstat_options',$s);
      $r=activate_plugin('wp-slimstat-pro/wp-slimstat-pro.php'); if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());
    `);
    const reactivation=JSON.parse(runWordPressFixture(`<?php echo wp_json_encode(['capture'=>wp_slimstat::$settings['heatmap_capture'],'fresh_pro'=>wp_slimstat::get_fresh_defaults()['heatmap_capture']]);`));
    expect(reactivation).toEqual({capture:'off',fresh_pro:'full'}); evidence.push(reactivation);
    const fresh=JSON.parse(runWordPressFixture(`<?php
      if (DB_NAME !== 'tests-wordpress') throw new RuntimeException('Disposable DB required');
      global $wpdb; $prefix=$wpdb->prefix; $options=$wpdb->options; $scratch='october_fresh_options'; $result=[];
      if ($wpdb->get_var("SHOW TABLES LIKE '$scratch'")) throw new RuntimeException('Unrestored fresh fixture');
      $wpdb->query("CREATE TABLE $scratch LIKE $options");
      try {
        foreach ([true,false] as $pro) {
          $wpdb->query("TRUNCATE TABLE $scratch");
          $wpdb->query("INSERT INTO $scratch SELECT * FROM $options WHERE option_name != 'slimstat_options'");
          $wpdb->set_prefix('october_fresh_'); wp_cache_flush();
          if (!$pro) update_option('active_plugins',array_values(array_diff(get_option('active_plugins'),['wp-slimstat-pro/wp-slimstat-pro.php'])));
          wp_slimstat::init(); $saved=get_option('slimstat_options');
          $result[$pro?'pro':'core']=['capture'=>$saved['heatmap_capture'],'effective'=>wp_slimstat::$settings['heatmap_capture']];
          $wpdb->set_prefix($prefix); wp_cache_flush();
        }
      } finally { $wpdb->set_prefix($prefix); wp_cache_flush(); $wpdb->query("DROP TABLE $scratch"); }
      echo wp_json_encode($result);
    `));
    expect(fresh).toEqual({pro:{capture:'full',effective:'full'},core:{capture:'off',effective:'off'}}); evidence.push({fresh});
    runWordPressFixture("<?php delete_option('slimstat_options');");
    const recovered=JSON.parse(runWordPressFixture("<?php echo wp_json_encode(['capture'=>wp_slimstat::$settings['heatmap_capture'],'recovery'=>wp_slimstat::$settings['_settings_recovery']??false]);"));
    expect(recovered).toEqual({capture:'off',recovery:true}); evidence.push({missingOptionsWithExistingData:recovered});
    await info.attach('settings-preservation.json',{body:JSON.stringify(evidence,null,2),contentType:'application/json'});
  } finally { runWordPressFixture("<?php delete_option('october_upgrade_expected');"); await restoreSlimstatOptions(); await closeDb(); }
});
