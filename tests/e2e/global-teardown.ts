/**
 * Global teardown: removes all test MU-plugins installed by global-setup.
 */
import { uninstallAllTestMuPlugins, uninstallCptMuPlugin, cleanupFixtureFiles, clearHeaderOverrides } from './helpers/setup';

export default async function globalTeardown(): Promise<void> {
  clearHeaderOverrides();
  cleanupFixtureFiles();
  // Reset the global flag before uninstalling so the calls actually remove files
  uninstallAllTestMuPlugins();
  // CPT mu-plugin is not in the manifest — clean it up separately
  // Force direct removal since globalMuPluginsManaged is now false
  uninstallCptMuPlugin();
}
