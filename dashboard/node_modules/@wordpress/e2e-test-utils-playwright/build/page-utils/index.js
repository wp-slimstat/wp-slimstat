"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.PageUtils = void 0;
/**
 * Internal dependencies
 */
const drag_files_1 = require("./drag-files");
const is_current_url_1 = require("./is-current-url");
const press_keys_1 = require("./press-keys");
const set_browser_viewport_1 = require("./set-browser-viewport");
class PageUtils {
    browser;
    page;
    context;
    constructor({ page }) {
        this.page = page;
        this.context = page.context();
        this.browser = this.context.browser();
    }
    /** @borrows dragFiles as this.dragFiles */
    dragFiles = drag_files_1.dragFiles.bind(this);
    /** @borrows isCurrentURL as this.isCurrentURL */
    isCurrentURL = is_current_url_1.isCurrentURL.bind(this);
    /** @borrows pressKeys as this.pressKeys */
    pressKeys = press_keys_1.pressKeys.bind(this);
    /** @borrows setBrowserViewport as this.setBrowserViewport */
    setBrowserViewport = set_browser_viewport_1.setBrowserViewport.bind(this);
    /** @borrows setClipboardData as this.setClipboardData */
    setClipboardData = press_keys_1.setClipboardData.bind(this);
}
exports.PageUtils = PageUtils;
//# sourceMappingURL=index.js.map