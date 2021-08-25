(function ($, mw, OO, ve) {
  'use strict';

  // Based partly on mw.flow.ve.ui.MentionInspector
  /**
   * Inspector for editing Sanctions agreement.  This is a friendly
   * UI for a transclusion (e.g. {{ping}}, template varies by wiki).
   *
   * @class
   * @extends ve.ui.NodeInspector
   *
   * @constructor
   * @param {Object} [config] Configuration options
   */
  mw.sanctions.ve.ui.AgreeInspector = function SanctionsVeUiAgreeInspector() {
    // Parent constructor
    mw.sanctions.ve.ui.AgreeInspector.super.apply(this, arguments);

    // this.selectedNode is the ve.dm.MWTransclusionNode, which we inherit
    // from ve.ui.NodeInspector.
    //
    // The templateModel (used locally some places) is a sub-part of the transclusion
    // model.
    this.transclusionModel = null;
    this.loaded = false;
    this.altered = false;

    this.expirationInput = null;
    this.selectedAt = false;
  };

  OO.inheritClass(mw.sanctions.ve.ui.AgreeInspector, ve.ui.NodeInspector);

  // Static

  mw.sanctions.ve.ui.AgreeInspector.static.name = 'sanctions-agree';
  mw.sanctions.ve.ui.AgreeInspector.static.size = 'medium';
  mw.sanctions.ve.ui.AgreeInspector.static.title = OO.ui.deferMsg(
    'sanctions-ve-agree-days-inspector-title'
  );
  mw.sanctions.ve.ui.AgreeInspector.static.modelClasses = [
    ve.dm.MWTransclusionNode,
  ];

  mw.sanctions.ve.ui.AgreeInspector.static.template = mw.config.get(
    'wgSanctionsAgreeTemplate'
  );

  mw.sanctions.ve.ui.AgreeInspector.static.templateParameterKey = '1'; // 1-indexed positional parameter

  // Buttons
  mw.sanctions.ve.ui.AgreeInspector.static.actions = [
    {
      action: 'remove',
      label: OO.ui.deferMsg('sanctions-ve-agree-days-inspector-remove-label'),
      flags: ['destructive'],
      modes: 'edit',
    },
  ].concat(mw.sanctions.ve.ui.AgreeInspector.super.static.actions);

  // Instance Methods

  /**
   * Handle changes to the input widget
   */
  mw.sanctions.ve.ui.AgreeInspector.prototype.onExpirationInputChange =
    function () {
      var templateModel, parameterModel, key, value, inspector;

      key = mw.sanctions.ve.ui.AgreeInspector.static.templateParameterKey;
      value = this.expirationInput.getValue();
      inspector = this;

      if (this.expirationInput.getValue()) {
        // After the updates are done, we'll get onTransclusionModelChange
        templateModel = inspector.transclusionModel.getParts()[0];
        if (templateModel.hasParameter(key)) {
          parameterModel = templateModel.getParameter(key);
          parameterModel.setValue(value);
        } else {
          parameterModel = new ve.dm.MWParameterModel(
            templateModel,
            key,
            value
          );
          templateModel.addParameter(parameterModel);
        }
      } else {
        // Disable save button
        inspector.setApplicableStatus();
      }
    };

  /**
   * Handle the transclusion becoming ready
   */
  mw.sanctions.ve.ui.AgreeInspector.prototype.onTransclusionReady =
    function () {
      var templateModel, key;

      key = mw.sanctions.ve.ui.AgreeInspector.static.templateParameterKey;

      this.loaded = true;
      this.$element.addClass('sanctions-ve-ui-agreeInspector-ready');
      this.popPending();

      templateModel = this.transclusionModel.getParts()[0];
      if (templateModel.hasParameter(key)) {
        this.expirationInput.setValue(
          templateModel.getParameter(key).getValue()
        );
      }
    };

  /**
   * Handles the transclusion model changing.  This should only happen when we change
   * the parameter, then get a callback.
   */
  mw.sanctions.ve.ui.AgreeInspector.prototype.onTransclusionModelChange =
    function () {
      if (this.loaded) {
        this.altered = true;
        this.setApplicableStatus();
      }
    };

  /**
   * Sets the abilities based on the current status
   *
   * If it's empty or invalid, it can not be inserted or updated.
   */
  mw.sanctions.ve.ui.AgreeInspector.prototype.setApplicableStatus =
    function () {
      var parts = this.transclusionModel.getParts(),
        templateModel = parts[0],
        key = mw.sanctions.ve.ui.AgreeInspector.static.templateParameterKey,
        inspector = this;

      // The template should always be there; the question is whether the first/only
      // positional parameter is.
      //
      // If they edit an existing mention, and make it invalid, they should be able
      // to cancel, but not save.
      if (templateModel.hasParameter(key)) {
        inspector.actions.setAbilities({
          done: !!this.expirationInput.getValue(),
        });
      } else {
        inspector.actions.setAbilities({ done: false });
      }
    };

  /**
   * Initialize UI of inspector
   */
  mw.sanctions.ve.ui.AgreeInspector.prototype.initialize = function () {
    var overlay;

    // Parent method
    mw.sanctions.ve.ui.AgreeInspector.super.prototype.initialize.apply(
      this,
      arguments
    );

    // Properties
    overlay = this.manager.getOverlay();

    this.expirationInput = new OO.ui.TextInputWidget({
      $overlay: overlay ? overlay.$element : this.$frame,
      value: '',
      // The next line is commented out as error is raised on Firefox.
      // See https://github.com/femiwiki/Sanctions/issues/61
      // type: 'number',
      validate: function (value) {
        if (value <= mw.config.get('wgSanctionsMaxBlockPeriod')) {
          return true;
        }
        return false;
      },
    });

    // Initialization
    this.$content.addClass('sanctions-ve-ui-agreeInspector-content');
    this.form.$element.append(this.expirationInput.$element);
  };

  mw.sanctions.ve.ui.AgreeInspector.prototype.getActionProcess = function (
    action
  ) {
    var deferred,
      inspector,
      transclusionModelPlain,
      surfaceModel = this.getFragment().getSurface();

    if (action === 'done') {
      deferred = $.Deferred();
      inspector = this;

      if (this.expirationInput.getValue()) {
        transclusionModelPlain = inspector.transclusionModel.getPlainObject();

        // Should be either null or the right template
        if (inspector.selectedNode instanceof ve.dm.MWTransclusionNode) {
          inspector.transclusionModel.updateTransclusionNode(
            surfaceModel,
            inspector.selectedNode
          );
        } else if (transclusionModelPlain !== null) {
          // Insert at the end of the fragment, unless we have an '@' selected, in which
          // case leave it selected so it gets removed.
          if (!inspector.selectedAt) {
            inspector.fragment = inspector.getFragment().collapseToEnd();
          }
          inspector.transclusionModel.insertTransclusionNode(
            inspector.getFragment(),
            'inline'
          );
          // After insertion move cursor to end of template
          inspector.fragment.collapseToEnd().select();
        }

        inspector.close({ action: action });
        deferred.resolve();
      } else {
        deferred.reject(
          new OO.ui.Error(
            OO.ui.msg(
              'sanctions-ve-agree-days-inspector-invalid-value',
              inspector.expirationInput.getValue()
            )
          )
        );
      }

      return new OO.ui.Process(deferred.promise());
    } else if (action === 'remove') {
      return new OO.ui.Process(function () {
        this.getFragment().removeContent();

        this.close({ action: action });
      }, this);
    }

    // Parent method
    return mw.sanctions.ve.ui.AgreeInspector.super.prototype.getActionProcess.apply(
      this,
      arguments
    );
  };

  // Technically, these are private.  However, it's necessary to override them (and not call
  // the parent), since otherwise this UI (which was probably designed for dialogs) does not fit the inspector.
  // Only handles on error at a time for now.
  //
  // It would be nice to implement a general solution for this that covers all inspectors (or
  // maybe a mixin for inline errors next to form elements).

  /**
   * Pre-populate the username based on the node
   *
   * @param {Object} [data] Inspector initial data
   * @param {boolean} [data.selectAt] Select the '@' symbol to the left of the fragment
   * @return {OO.ui.Process}
   */
  mw.sanctions.ve.ui.AgreeInspector.prototype.getSetupProcess = function (
    data
  ) {
    // Parent method
    return mw.sanctions.ve.ui.AgreeInspector.super.prototype.getSetupProcess
      .apply(this, arguments)
      .next(function () {
        var templateModel, promise, atFragment;

        this.loaded = false;
        this.altered = false;
        // MWTransclusionModel has some unnecessary behavior for our use
        // case, mainly templatedata lookups.
        this.transclusionModel = new ve.dm.MWTransclusionModel();

        // Events
        this.transclusionModel.connect(this, {
          change: 'onTransclusionModelChange',
        });

        this.expirationInput.connect(this, {
          change: 'onExpirationInputChange',
        });

        // Initialization
        if (!this.selectedNode) {
          this.actions.setMode('insert');
          templateModel = ve.dm.MWTemplateModel.newFromName(
            this.transclusionModel,
            mw.sanctions.ve.ui.AgreeInspector.static.template
          );
          promise = this.transclusionModel.addPart(templateModel);
        } else {
          this.actions.setMode('edit');

          // Load existing ping
          promise = this.transclusionModel.load(
            ve.copy(this.selectedNode.getAttribute('mw'))
          );
        }

        if (data.selectAt) {
          atFragment = this.getFragment().adjustLinearSelection(-1, 0);
          if (atFragment.getText() === '@') {
            this.fragment = atFragment.select();
            this.selectedAt = true;
          }
        }

        // Don't allow saving until we're sure it's valid.
        this.actions.setAbilities({ done: false });
        this.pushPending();
        promise.always(this.onTransclusionReady.bind(this));
      }, this);
  };

  mw.sanctions.ve.ui.AgreeInspector.prototype.getReadyProcess = function () {
    // Parent method
    return mw.sanctions.ve.ui.AgreeInspector.super.prototype.getReadyProcess
      .apply(this, arguments)
      .next(function () {
        this.expirationInput.focus();
      }, this);
  };

  mw.sanctions.ve.ui.AgreeInspector.prototype.getTeardownProcess = function () {
    // Parent method
    return mw.sanctions.ve.ui.AgreeInspector.super.prototype.getTeardownProcess
      .apply(this, arguments)
      .first(function () {
        // Cleanup
        this.$element.removeClass('flow-ve-ui-mentionInspector-ready');
        this.transclusionModel.disconnect(this);
        this.transclusionModel.abortAllApiRequests();
        this.transclusionModel = null;

        this.expirationInput.disconnect(this);

        this.expirationInput.setValue('');
        if (this.selectedAt) {
          this.fragment.collapseToEnd().select();
        }
        this.selectedAt = false;
      }, this);
  };

  /**
   * Gets the transclusion node representing this mention
   *
   * @return {ve.dm.Node|null} Selected node
   */
  mw.sanctions.ve.ui.AgreeInspector.prototype.getSelectedNode = function () {
    // Parent method
    var node =
      mw.sanctions.ve.ui.AgreeInspector.super.prototype.getSelectedNode.apply(
        this,
        arguments
      );
    // Checks the model class
    if (
      node &&
      node.isSingleTemplate(mw.sanctions.ve.ui.AgreeInspector.static.template)
    ) {
      return node;
    }

    return null;
  };

  ve.ui.windowFactory.register(mw.sanctions.ve.ui.AgreeInspector);
})(jQuery, mediaWiki, OO, ve);
