import type * as Platform from '../../../core/platform/platform.js';
import * as ThirdPartyWeb from '../../../third_party/third-party-web/third-party-web.js';
import * as Types from '../types/types.js';
import type { TraceEventsForNetworkRequest } from './NetworkRequestsHandler.js';
import type { ParsedTrace } from './types.js';
export type Entity = typeof ThirdPartyWeb.ThirdPartyWeb.entities[number] & {
    isUnrecognized?: boolean;
};
export interface EntityMappings {
    createdEntityCache: Map<string, Entity>;
    entityByEvent: Map<Types.Events.Event, Entity>;
    /**
     * This holds the entities that had to be created, because they were not found using the
     * ThirdPartyWeb database.
     */
    eventsByEntity: Map<Entity, Types.Events.Event[]>;
}
export declare function getEntityForEvent(event: Types.Events.Event, entityCache: Map<string, Entity>): Entity | undefined;
export declare function getEntityForUrl(url: string, entityCache: Map<string, Entity>): Entity | undefined;
export declare function getNonResolvedURL(entry: Types.Events.Event, parsedTrace?: ParsedTrace): Platform.DevToolsPath.UrlString | null;
export declare function makeUpEntity(entityCache: Map<string, Entity>, url: string): Entity | undefined;
export declare function addEventToEntityMapping(event: Types.Events.Event, entityMappings: EntityMappings): void;
export declare function addNetworkRequestToEntityMapping(networkRequest: Types.Events.SyntheticNetworkRequest, entityMappings: EntityMappings, requestTraceEvents: TraceEventsForNetworkRequest): void;
