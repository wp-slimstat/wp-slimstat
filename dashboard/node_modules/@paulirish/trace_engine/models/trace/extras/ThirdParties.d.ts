import * as ThirdPartyWeb from '../../../third_party/third-party-web/third-party-web.js';
import * as Handlers from '../handlers/handlers.js';
import * as Types from '../types/types.js';
export type Entity = typeof ThirdPartyWeb.ThirdPartyWeb.entities[number];
export interface Summary {
    transferSize: number;
    mainThreadTime: Types.Timing.Micro;
}
export interface ThirdPartySummary {
    byEntity: Map<Entity, Summary>;
    byUrl: Map<string, Summary>;
    urlsByEntity: Map<Entity, Set<string>>;
    eventsByEntity: Map<Entity, Types.Events.Event[]>;
    madeUpEntityCache: Map<string, Entity>;
}
/**
 * @param networkRequests Won't be filtered by trace bounds, so callers should ensure it is filtered.
 */
export declare function summarizeThirdParties(parsedTrace: Handlers.Types.ParsedTrace, traceBounds: Types.Timing.TraceWindowMicro, networkRequests: Types.Events.SyntheticNetworkRequest[]): ThirdPartySummary;
/**
 * Note: unlike summarizeThirdParties, this does not calculate mainThreadTime. The reason is that it is not
 * needed for its one use case, and when dragging the trace bounds it takes a long time to calculate.
 * If it is ever needed, we need to make getSelfTimeByUrl (see deleted code/blame) much faster (cache + bucket?).
 */
export declare function getSummariesAndEntitiesWithMapping(parsedTrace: Handlers.Types.ParsedTrace, traceBounds: Types.Timing.TraceWindowMicro, entityMapping: Handlers.Helpers.EntityMappings): {
    summaries: ThirdPartySummary;
    entityByEvent: Map<Types.Events.Event, Handlers.Helpers.Entity>;
};
