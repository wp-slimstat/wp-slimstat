/**
 * Global teardown: removes all test MU-plugins installed by global-setup.
 */
import { uninstallAllTestMuPlugins, uninstallCptMuPlugin, cleanupFixtureFiles, clearHeaderOverrides, disableE2eTesting } from './helpers/setup';

export default async function globalTeardown(): Promise<void> {
  clearHeaderOverrides();
  cleanupFixtureFiles();
  // Reset the global flag before uninstalling so the calls actually remove files
  uninstallAllTestMuPlugins();
  // CPT mu-plugin is not in the manifest — clean it up separately
  // Force direct removal since globalMuPluginsManaged is now false
  uninstallCptMuPlugin();
  // Remove the SLIMSTAT_E2E_TESTING define injected by global setup. Runs in a
  // separate process from the injector, so this strips the line directly rather
  // than relying on the in-memory wp-config backup.
  disableE2eTesting();
}
