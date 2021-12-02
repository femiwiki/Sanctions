'use strict';

class FlowApi {
  reply(msg, uuid, bot) {
    browser.call(async () => {
      await bot.request({
        action: 'flow',
        submodule: 'reply',
        page: `Topic:${uuid}`,
        repreplyTo: uuid,
        repcontent: msg,
        repformat: 'wikitext',
        token: bot.editToken,
      });
    });
  }

  editTopicSummary(msg, uuid, bot) {
    browser.call(async () => {
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
    });
  }
}

module.exports = new FlowApi();
