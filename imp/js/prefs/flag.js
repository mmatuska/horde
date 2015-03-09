/**
 * Managing message flags.
 *
 * @author     Michael Slusarz <slusarz@horde.org>
 * @copyright  2014-2015 Horde LLC
 * @license    GPL-2 (http://www.horde.org/licenses/gpl)
 */

var ImpFlagPrefs = {

    // Variables set by other code: confirm_delete, new_prompt

    addFlag: function()
    {
        var category = window.prompt(this.new_prompt, '');
        if (category) {
            this._sendData('add', category);
        }
    },

    _sendData: function(a, d)
    {
        $('flag_action').setValue(a);
        $('flag_data').setValue(d);
        $('prefs').submit();
    },

    changeHandler: function(e, elt)
    {
        if (elt.identify().startsWith('bg_')) {
            elt.setStyle({ background: elt.getValue() });
        }
    },

    clickHandler: function(e)
    {
        var cnames, elt2,
            elt = e.element();

        if (elt.readAttribute('id') == 'new_button') {
            this.addFlag();
        } else {
            cnames = $w(elt.className);

            if (cnames.indexOf('flagcolorpicker') !== -1) {
                elt2 = elt.previous('INPUT');
                new ColorPicker({
                    color: $F(elt2),
                    draggable: true,
                    offsetParent: elt,
                    resizable: true,
                    update: [
                        [ elt2, 'value' ],
                        [ elt2, 'background' ]
                    ]
                });
                e.memo.stop();
            } else if (cnames.indexOf('flagdelete') !== -1) {
                if (window.confirm(this.confirm_delete)) {
                    this._sendData('delete', elt.previous('INPUT').readAttribute('id'));
                }
                e.memo.stop();
            }
        }
    },

    resetHandler: function()
    {
        $('prefs').getInputs('text').each(function(i) {
            if (i.readAttribute('id').startsWith('color_')) {
                i.setStyle({ backgroundColor: $F(i) });
            }
        });
    },

    onDomLoad: function()
    {
        HordeCore.initHandler('click');
        $('prefs').observe('reset', function() {
            this.resetHandler.delay(0.1);
        }.bind(this));
    }

};

document.observe('dom:loaded', ImpFlagPrefs.onDomLoad.bind(ImpFlagPrefs));
document.observe('HordeCore:click', ImpFlagPrefs.clickHandler.bindAsEventListener(ImpFlagPrefs));
document.on('change', 'INPUT', ImpFlagPrefs.changeHandler.bind(ImpFlagPrefs));
