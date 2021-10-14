'use strict';

class FlowApi {
  reply(msg, uuid, voter) {
    browser.call(async () => {
      await voter.request({
        action: 'flow',
        submodule: 'reply',
        page: `Topic:${uuid}`,
        repreplyTo: uuid,
        repcontent: msg,
        repformat: 'wikitext',
        token: voter.editToken,
      });
    });
  }
}

module.exports = new FlowApi();
