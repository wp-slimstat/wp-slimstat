import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { resolve } from 'node:path';
const exec = promisify(execFile);
export async function fixtureScript(script: string, args: string[] = []) {
  if (!process.env.FORMS_QA_SITE) throw new Error('Provide the disposable WordPress path.');
  const {stdout} = await exec(process.env.FORMS_QA_PHP || 'php', ['-d', 'memory_limit=512M', process.env.FORMS_QA_WP || '/opt/homebrew/bin/wp', 'eval-file', resolve(script), ...args, '--path='+process.env.FORMS_QA_SITE]);
  return stdout;
}
export async function snapshot(key: string, id: number) {
  const stdout = await fixtureScript('tests/forms/snapshot.php', [key, String(id)]);
  const line = stdout.split('\n').find(line=>line.startsWith('FORMS_QA_JSON:'));
  if (!line) throw new Error('The isolated database reader returned no result.');
  return JSON.parse(line.slice('FORMS_QA_JSON:'.length));
}
