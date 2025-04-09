import type * as Handlers from '../handlers/handlers.js';
import type * as Types from '../types/types.js';
import { type InsightModel, type InsightSetContext } from './types.js';
export declare const UIStrings: {
    /** Title of an insight that provides details about if the page's viewport is optimized for mobile viewing. */
    readonly title: "Optimize viewport for mobile";
    /**
     * @description Text to tell the user how a viewport meta element can improve performance. \xa0 is a non-breaking space
     */
    readonly description: "Tap interactions may be [delayed by up to 300 ms](https://developer.chrome.com/blog/300ms-tap-delay-gone-away/) if the viewport is not optimized for mobile.";
};
export declare const i18nString: (id: string, values?: Record<string, string> | undefined) => Record<string, string>;
export type ViewportInsightModel = InsightModel<typeof UIStrings, {
    mobileOptimized: boolean | null;
    viewportEvent?: Types.Events.ParseMetaViewport;
}>;
export declare function generateInsight(parsedTrace: Handlers.Types.ParsedTrace, context: InsightSetContext): ViewportInsightModel;
