import { expect, type Locator } from '@playwright/test';

/**
 * Set a settings toggle the way a user does and verify its form value: a role="switch"
 * checkbox is clicked; a two-option segmented group (the "on" radio keeps the setting's id)
 * gets the label of the wanted option clicked.
 */
export async function setSettingsToggle(control: Locator, checked: boolean): Promise<void> {
  if ((await control.getAttribute('type')) === 'radio') {
    const name = await control.getAttribute('name');
    await control.page().locator(`input[type=radio][name="${name}"][value="${checked ? 'on' : 'no'}"]`).locator('..').click();
  } else {
    await control.setChecked(checked);
  }
  await expect(control).toBeChecked({ checked });
}
