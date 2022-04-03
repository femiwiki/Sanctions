'use strict';

class FlowApi {
  async reply(msg, uuid, bot) {
    await bot.request({
      action: 'flow',
      submodule: 'reply',
      page: `Topic:${uuid}`,
      repreplyTo: uuid,
      repcontent: msg,
      repformat: 'wikitext',
      token: bot.editToken,
    });
  }

  async editTopicSummary(msg, uuid, bot) {
    const res = await bot.request({
      action: 'flow',
      submodule: 'view-topic-summary',
      page: `Topic:${uuid}`,
      vtsformat: 'wikitext',
    });
    const prevRev =
      res['flow']['view-topic-summary']['result']['topicsummary']['revision'][
        'revisionId'
      ];

    await bot.request({
      action: 'flow',
      submodule: 'edit-topic-summary',
      page: `Topic:${uuid}`,
      etsprev_revision: prevRev,
      etssummary: msg,
      etsformat: 'wikitext',
      token: bot.editToken,
    });
  }
}

module.exports = new FlowApi();
