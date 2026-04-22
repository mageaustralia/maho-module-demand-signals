import { defineConfig } from '@playwright/test';
import baseConfig from '@mageaustralia/maho-playwright-rig/config';

export default defineConfig({
    ...baseConfig,
    testDir: './tests/playwright',
});
