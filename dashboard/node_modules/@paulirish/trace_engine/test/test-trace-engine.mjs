// Copyright 2023 The Chromium Authors. All rights reserved.
// Use of this source code is governed by a BSD-style license that can be
// found in the LICENSE file.

import {strict as assert} from 'assert';
import test from 'node:test';

import {analyzeTrace} from '../analyze-trace.mjs';

const filename = './test/invalid-animation-events.json.gz';
const {parsedTrace: data, insights} = await analyzeTrace(filename);

test('key values are populated', t => {
  assert.equal(data.Renderer.allTraceEntries.length > 90_000, true);
  assert.equal((data.Screenshots.legacySyntheticScreenshots?.length ?? 0) > 2, true);
  assert.equal(data.Meta.threadsInProcess.size > 2, true);
  assert.equal(data.Meta.mainFrameNavigations.length > 0, true);
});

test('numeric values are set and look legit', t => {
  const shouldBeNumbers = [
    data.Meta.traceBounds.min,
    data.Meta.traceBounds.max,
    data.Meta.traceBounds.range,
    data.Meta.browserProcessId,
    data.Meta.browserThreadId,
    data.Meta.gpuProcessId,
    data.Meta.gpuThreadId,
    Array.from(data.Meta.topLevelRendererIds.values()).at(0),
    Array.from(data.Meta.frameByProcessId.keys()).at(0),
  ];
  for (const datum of shouldBeNumbers) {
    assert.equal(typeof datum, 'number');
    if (typeof datum !== 'number')
      throw new Error();
    assert.equal(isNaN(datum), false);
    assert.equal(datum > 10, true);
  }
});

test('string values are set and look legit', t => {
  const shouldBeStrings = [
    data.Meta.mainFrameId,
    data.Meta.mainFrameURL,
    Array.from(data.Meta.navigationsByFrameId.keys()).at(0),
    Array.from(data.Meta.navigationsByNavigationId.keys()).at(0),
    data.Meta.mainFrameId,
  ];

  for (const datum of shouldBeStrings) {
    assert.equal(typeof datum, 'string');
    if (typeof datum !== 'string')
      throw new Error();
    assert.equal(datum.length > 10, true);
  }
});

test('insights look ok', t => {
  if (insights === null) {
    throw new Error('insights null');
  }
  // First insightset with a navigation on it, to skip over the NO_NAV one.
  const insightSet = Array.from(insights.values()).find(is => is.navigation);
  if (typeof insightSet === 'undefined') {
    throw new Error();
  }
  const keys = Object.keys(insightSet.model);
  assert.deepStrictEqual(keys, [
    'InteractionToNextPaint',
    'LCPPhases',
    'LCPDiscovery',
    'CLSCulprits',
    'RenderBlocking',
    'NetworkDependencyTree',
    'ImageDelivery',
    'DocumentLatency',
    'FontDisplay',
    'Viewport',
    'DOMSize',
    'ThirdParties',
    'DuplicatedJavaScript',
    'SlowCSSSelector',
    'ForcedReflow',
    'UseCache',
  ]);
  for (const [insightName, insightItem] of Object.entries(insightSet.model)) {
    const msg = insightItem instanceof Error ?
        `${insightName} is an error. ${insightItem.toString()} ${insightItem.stack?.toString()}` :
        '';
    assert.ok(insightItem instanceof Error === false, msg);
    assert.ok(typeof insightItem === 'object', `insightName ${insightName} is not an object`);
  }

  const entityNames = Array.from(insightSet.model.ThirdParties.summaryByEntity.keys()).map(e => e.name);
  const values = Array.from(insightSet.model.ThirdParties.summaryByEntity.values());
  const simplified = Object.fromEntries(values.map((v, i) => [entityNames[i], v]));

  const expected = {
    ahfhijdlegdabablpippeagghigmibma: { transferSize: 0, mainThreadTime: 2189 },
    'paulirish.com': { transferSize: 142142, mainThreadTime: 8995 },
    cjpalhdlnbpafiamejdnhcphjbkeiagm: { transferSize: 0, mainThreadTime: 2607 },
    jinjaccalgkegednnccohejagnlnfdag: { transferSize: 0, mainThreadTime: 841 },
    noondiphcddnnabmjcihcjfbhfklnnep: { transferSize: 0, mainThreadTime: 1811 },
    'Google Tag Manager': { transferSize: 0, mainThreadTime: 124 },
    Disqus: { transferSize: 1550, mainThreadTime: 413 },
    'Google Analytics': { transferSize: 0, mainThreadTime: 117 },
    bknnlbamapndemiekhkcnmdclnkijlhb: { transferSize: 0, mainThreadTime: 387 },
    kljjfejkagofbgklifblndjelgabcmig: { transferSize: 0, mainThreadTime: 149 },
    nffaoalbilbmmfgbnbgppjihopabppdk: { transferSize: 0, mainThreadTime: 812 },
    obadmbiiipafnncogfkdfionggeckfia: { transferSize: 0, mainThreadTime: 1477 },
    'Google Fonts': { transferSize: 74145, mainThreadTime: 0 }
  };
  assert.deepStrictEqual(simplified, expected);
});
