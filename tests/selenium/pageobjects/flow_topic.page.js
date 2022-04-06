'use strict';
const Page = require('wdio-mediawiki/Page');
const Api = require('wdio-mediawiki/Api');

class FlowTopic extends Page {
  get replyButton() {
    return $('.flow-ui-replyWidget .oo-ui-inputWidget-input');
  }
  get replyEditor() {
    return $('.flow-ui-replyWidget [role="textbox"]');
  }
  get replySaveButton() {
    return $('.flow-ui-editorControlsWidget-saveButton a');
  }
  get topicSummary() {
    return $('.flow-topic-summary-content');
  }

  async reply(msg) {
    await this.replyButton.waitForDisplayed();
    await this.replyButton.waitForClickable();
    await this.replyButton.click();
    await this.replyEditor.waitForDisplayed();
    await this.replyEditor.setValue(msg);
    await this.replySaveButton.click();
  }

  async open(subpage) {
    super.openTitle('Special:Sanctions/' + subpage, { uselang: 'qqx' });
  }
}
module.exports = new FlowTopic();
