import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import { adminLogin } from '@mageaustralia/maho-playwright-rig/helpers/admin';
import { resetRig } from '@mageaustralia/maho-playwright-rig/helpers/reset';

test.describe('MageAustralia_DemandSignals - admin + canary', () => {
    test.beforeAll(() => {
        resetRig();
    });

    test('admin Reports menu exposes Demand Signals entries', async ({ page }) => {
        await adminLogin(page);
        // The admin menu renders Reports -> Demand Signals. Follow the href
        // directly so the assertion doesn't depend on hover behaviour.
        const top = await page.goto('/admin/demandsignals_report/products');
        expect(top?.status()).toBeLessThan(400);
        const title = await page.textContent('h1, .page-title, .head-catalog-products');
        expect((title ?? '').toLowerCase()).toContain('demand');

        const search = await page.goto('/admin/demandsignals_report/search');
        expect(search?.status()).toBeLessThan(400);
    });

    test('storefront homepage renders without fatal', async ({ page }) => {
        const response = await page.goto('/');
        expect(response?.status()).toBe(200);
        const body = await page.content();
        expect(body).not.toContain('Fatal error');
        expect(body).not.toContain('Uncaught Error');
    });
});

test.describe('MageAustralia_DemandSignals - signal recording', () => {
    test.beforeAll(() => {
        resetRig({ flavor: 'sample' });
        // Module is on by default; no further config needed for this test.
    });

    test('zero-result search inserts a search_no_results row', async ({ page }) => {
        // Fire a storefront search for a term that sample data won't match.
        const nonsense = `zzz_demand_${Date.now()}`;
        await page.goto(`/catalogsearch/result/?q=${encodeURIComponent(nonsense)}`);

        // Maho saves the search query in catalog_search_query with num_results
        // after the result controller runs. Give the request a moment, then
        // verify a row landed in our event table.
        const out = execSync(
            `docker exec maho-rig-db mariadb -umaho -pmaho maho -sN -e "
            SELECT COUNT(*) FROM mageaustralia_demandsignals_event
            WHERE signal_type = 'search_no_results'
              AND entity_key LIKE '%${nonsense}%';"`,
            { encoding: 'utf8' },
        );

        expect(parseInt(out.trim(), 10)).toBeGreaterThan(0);
    });

    test('rollup cron produces an aggregate row', async () => {
        execSync(
            `docker exec maho-rig-web php -r '` +
            `require "/app/vendor/autoload.php"; Mage::app(); ` +
            `Mage::getModel("mageaustralia_demandsignals/aggregator")->rollupHourly();'`,
            { stdio: 'pipe' },
        );

        const out = execSync(
            `docker exec maho-rig-db mariadb -umaho -pmaho maho -sN -e "
            SELECT COUNT(*) FROM mageaustralia_demandsignals_aggregate
            WHERE signal_type = 'search_no_results';"`,
            { encoding: 'utf8' },
        );

        expect(parseInt(out.trim(), 10)).toBeGreaterThan(0);
    });
});
