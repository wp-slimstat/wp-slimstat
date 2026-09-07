import { expect, type Locator } from '@playwright/test';

/** Click the visible Bootstrap Switch control and verify its underlying form value. */
export async function setSettingsToggle(checkbox: Locator, checked: boolean): Promise<void> {
  if (await checkbox.isChecked() !== checked) {
    await checkbox.locator('..').locator('.bootstrap-switch-label').click();
  }
  await expect(checkbox).toBeChecked({ checked });
}
