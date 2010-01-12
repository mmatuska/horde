/**
 * Reuseable keyboard or mouse driven list component. Based on
 * Scriptaculous' AutoCompleter.
 *
 * Requires: prototype.js (v1.6.1+)
 *
 * Usage:
 * ======
 * var knl = new KeyNavList(base, {
 *     'esc' - (boolean) Escape the displayed output?
 *     'list' - (array) Array of objects with the following keys:
 *                      'l' - (label) Display data
 *                      's' - (selected) True if this entry should be selected
 *                            by default
 *                      'v' - (value) Value of entry
 *     'onChoose' - (function) Called when an entry is selected. Passed the
 *                             entry value.
 *     'onHide' - (function) Called when the list is hidden. Passed the
 *                           list container element.
 *     'onShow' - (function) Called when the list is shown. Passed the
 *                           list container element.
 * });
 *
 * [base = (Element) The element to use for display positioning purposes]
 *
 * Copyright 2005-2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 */

var KeyNavList = Class.create({

    // Vars used: active, div, iefix, ignore, onClickFunc, onKeyDownFunc,
    //            resizeFunc, selected

    initialize: function(base, opts)
    {
        this.active = false;
        this.base = $(base);

        this.opts = Object.extend({
            onChoose: Prototype.emptyFunction,
            onHide: Prototype.emptyFunction,
            onShow: Prototype.emptyFunction,
            domParent: null
        }, opts || {});

        this.div = new Element('DIV', { className: 'KeyNavList' }).hide().insert(new Element('UL'));
        var parent = (this.opts.domParent) ? $(this.opts.domParent) : document.body;
        parent.appendChild(this.div);

        if (this.opts.list) {
            this.update(this.opts.list);
            delete this.opts.list;
        }

        this.onClickFunc = this.onClick.bindAsEventListener(this);
        document.observe('click', this.onClickFunc);

        this.onKeyDownFunc = this.onKeyDown.bindAsEventListener(this);
        document.observe('keydown', this.onKeyDownFunc);

        this.resizeFunc = this.hide.bind(this);
        Event.observe(window, 'resize', this.resizeFunc);

        if (Prototype.Browser.IE && !window.XMLHttpRequest) {
            this.iefix = $('knl_iframe_iefix');
            if (!this.iefix) {
                this.iefix = new Element('IFRAME', { id: 'knl_iframe_iefix', src: 'javascript:false;', scrolling: 'no', frameborder: 0 }).setStyle({ position: 'absolute', display: 'block', zIndex: 99 }).hide();
                document.body.appendChild(this.iefix);
            }
            this.div.setStyle({ zIndex: 100 });
        }
    },

    destroy: function()
    {
        document.stopObserving('click', this.onClickFunc);
        document.stopObserving('keydown', this.onKeyDownFunc);
        Event.stopObserving(window, 'resize', this.resizeFunc);
        this.div.remove();
        if (this.iefix) {
            this.iefix.remove();
        }
    },

    update: function(list)
    {
        var df = document.createDocumentFragment();

        list.each(function(v) {
            var li;
            if (this.opts.esc) {
                v.l = v.l.escapeHTML().gsub('  ', ' &nbsp;');
            }
            li = new Element('LI').insert(v.l).store('v', v.v);
            if (v.s) {
                this.markSelected(li);
            }
            df.appendChild(li);
        }.bind(this));

        this.div.down().childElements().invoke('remove');
        this.div.down().appendChild(df);
    },

    updateBase: function(base)
    {
        this.base = $(base);
    },

    show: function(list)
    {
        this.active = true;

        if (!Object.isUndefined(list)) {
            this.update(list);
        } else if (this.div.visible()) {
            return;
        }

        this.opts.onShow(this.div);

        this.div.setStyle({ height: null, width: null, top: null }).clonePosition(this.base, {
            setHeight: false,
            setWidth: false,
            offsetTop: this.base.getHeight()
        });

        if (this.div.visible()) {
            this._sizeDiv();
        } else {
            this.div.appear({
                afterFinish: function() {
                    if (this.selected) {
                        this.div.scrollTop = this.selected.offsetTop;
                    }
                }.bind(this),
                afterSetup: this._sizeDiv.bind(this),
                duration: 0.15
            });
        }
    },

    _sizeDiv: function()
    {
        var l, w,
            t = parseInt(this.div.getStyle('top'), 10),
            v = document.viewport.getDimensions();

        if ((this.div.getHeight() + t + 10) > v.height) {
            l = parseInt(this.div.getStyle('left'), 10);
            w = (this.div.offsetWidth * 2) - this.div.scrollWidth;

            this.div.setStyle({ height: (v.height - t - 10) + 'px', left: (((l + w + 10) > v.width) ? (v.width - w - 20) : l) + 'px', width: (w + 10) + 'px' });
        }

        if (this.iefix) {
            this.iefix.clonePosition(this.div);
        }
    },

    hide: function()
    {
        if (this.div.visible()) {
            this.active = false;
            this.opts.onHide(this.div);
            this.div.fade({ duration: 0.15 });
            if (this.iefix) {
                this.iefix.hide();
            }
        }
    },

    onKeyDown: function(e)
    {
        if (!this.active) {
            return;
        }

        switch (e.keyCode) {
        case Event.KEY_TAB:
        case Event.KEY_RETURN:
            this.opts.onChoose(this.getCurrentEntry());
            this.hide();
            e.stop();
            break;

        case Event.KEY_ESC:
            this.hide();
            e.stop();
            return;

        case Event.KEY_UP:
            this.markPrevious();
            e.stop();
            return;

        case Event.KEY_DOWN:
            this.markNext();
            e.stop();
            return;
        }
    },

    onClick: function(e)
    {
        if (this.active && this.ignore != e) {
            var elt = e.findElement('LI');

            if (elt &&
                (elt == this.div || elt.descendantOf(this.div))) {
                this.markSelected(elt);
                this.opts.onChoose(this.getCurrentEntry());
                e.stop();
            }
            this.hide();
        }

        this.ignore = null;
    },

    ignoreClick: function(e)
    {
        this.ignore = e;
    },

    setSelected: function(value)
    {
        this.markSelected(this.div.down().childElements().find(function(e) {
            return e.retrieve('v') == value;
        }));
    },

    markSelected: function(elt)
    {
        if (this.selected) {
            this.selected.removeClassName('selected');
        }
        this.selected = elt
            ? elt.addClassName('selected')
            : null;
    },

    markPrevious: function()
    {
        this.markSelected(this.selected ? this.selected.previous() : null);
    },

    markNext: function()
    {
        var elt = this.selected
            ? this.selected.next()
            : this.div.down().childElements().first();

        if (elt) {
            this.markSelected(elt);
        }
    },

    getCurrentEntry: function()
    {
        return this.selected
            ? this.selected.retrieve('v')
            : null;
    }

});
