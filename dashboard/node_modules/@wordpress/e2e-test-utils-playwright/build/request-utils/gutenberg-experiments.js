"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.setGutenbergExperiments = setGutenbergExperiments;
/**
 * Sets the Gutenberg experiments.
 *
 * @param this
 * @param experiments Array of experimental flags to enable. Pass in an empty array to disable all experiments.
 */
async function setGutenbergExperiments(experiments) {
    const response = await this.request.get('/wp-admin/admin.php?page=gutenberg-experiments');
    const html = await response.text();
    const nonce = html.match(/name="_wpnonce" value="([^"]+)"/)[1];
    await this.request.post('/wp-admin/options.php', {
        form: {
            option_page: 'gutenberg-experiments',
            action: 'update',
            _wpnonce: nonce,
            _wp_http_referer: '/wp-admin/admin.php?page=gutenberg-experiments',
            ...Object.fromEntries(experiments.map((experiment) => [
                `gutenberg-experiments[${experiment}]`,
                1,
            ])),
            submit: 'Save Changes',
        },
        failOnStatusCode: true,
    });
}
//# sourceMappingURL=gutenberg-experiments.js.map