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

  reply(msg) {
    this.replyButton.waitForDisplayed();
    this.replyButton.click();
    this.replyEditor.waitForDisplayed();
    this.replyEditor.setValue(msg);
    this.replySaveButton.click();
  }

  open(subpage) {
    super.openTitle('Special:Sanctions/' + subpage, { uselang: 'qqx' });
  }
}
module.exports = new FlowTopic();
