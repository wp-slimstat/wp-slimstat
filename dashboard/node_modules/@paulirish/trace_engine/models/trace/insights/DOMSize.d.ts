import * as Handlers from '../handlers/handlers.js';
import * as Types from '../types/types.js';
import { type InsightModel, type InsightSetContext } from './types.js';
export declare const UIStrings: {
    /**
     * @description Title of an insight that recommends reducing the size of the DOM tree as a means to improve page responsiveness. "DOM" is an acronym and should not be translated.
     */
    readonly title: "Optimize DOM size";
    /**
     * @description Description of an insight that recommends reducing the size of the DOM tree as a means to improve page responsiveness. "DOM" is an acronym and should not be translated. "layout reflows" are when the browser will recompute the layout of content on the page.
     */
    readonly description: "A large DOM can increase the duration of style calculations and layout reflows, impacting page responsiveness. A large DOM will also increase memory usage. [Learn how to avoid an excessive DOM size](https://developer.chrome.com/docs/lighthouse/performance/dom-size/).";
    /**
     * @description Header for a column containing the names of statistics as opposed to the actual statistic values.
     */
    readonly statistic: "Statistic";
    /**
     * @description Header for a column containing the value of a statistic.
     */
    readonly value: "Value";
    /**
     * @description Header for a column containing the page element related to a statistic.
     */
    readonly element: "Element";
    /**
     * @description Label for a value representing the total number of elements on the page.
     */
    readonly totalElements: "Total elements";
    /**
     * @description Label for a value representing the maximum depth of the Document Object Model (DOM). "DOM" is a acronym and should not be translated.
     */
    readonly maxDOMDepth: "DOM depth";
    /**
     * @description Label for a value representing the maximum number of child elements of any parent element on the page.
     */
    readonly maxChildren: "Most children";
};
export declare const i18nString: (id: string, values?: Record<string, string> | undefined) => Record<string, string>;
export type DOMSizeInsightModel = InsightModel<typeof UIStrings, {
    largeLayoutUpdates: Types.Events.Layout[];
    largeStyleRecalcs: Types.Events.UpdateLayoutTree[];
    maxDOMStats?: Types.Events.DOMStats;
}>;
export declare function generateInsight(parsedTrace: Handlers.Types.ParsedTrace, context: InsightSetContext): DOMSizeInsightModel;
