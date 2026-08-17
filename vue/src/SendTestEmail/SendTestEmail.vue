<!--
  Missivus — send Matomo email through the Microsoft Graph API.
  @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later

  SystemSettings has no button primitive, so this component is mounted as a field's
  customFieldComponent. It stores nothing: modelValue is accepted so Matomo's field wrapper is
  happy, and ignored.

  The test sends with the SAVED settings, not with what is on screen, so the button stays disabled
  until Missivus.getTestEmailStatus says the stored configuration can send, and says why when it
  cannot. The status is re-checked whenever a settings save completes, so clicking Save enables the
  button without a page reload.

  ../dist/Missivus.umd.min.js is the hand-written equivalent that ships with the plugin, so that a
  Node toolchain is not needed to install it. Keep the two in step.
-->
<template>
  <div class="missivusTestEmail">
    <div class="missivusTestEmailRow">
      <input
        type="email"
        class="missivusTestEmailRecipient"
        :placeholder="translate('Missivus_TestEmailRecipientLabel')"
        :disabled="sending || !ready"
        v-model="recipient"
      />
      <button
        type="button"
        class="btn"
        :disabled="sending || !ready"
        @click.prevent="send"
      >
        {{ sending ? translate('Missivus_SendingTestEmail') : translate('Missivus_SendTestEmail') }}
      </button>
    </div>

    <div
      v-if="!ready && reason"
      class="notification system notification-info missivusTestEmailResult"
    >{{ reason }}</div>

    <div
      v-if="result"
      class="notification system missivusTestEmailResult"
      :class="result.success ? 'notification-success' : 'notification-error'"
    >{{ result.message }}</div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import { AjaxHelper, translate } from 'CoreHome';

interface SendTestEmailState {
  recipient: string;
  sending: boolean;
  ready: boolean;
  reason: string;
  result: { success: boolean; message: string } | null;
}

const { $ } = window;

export default defineComponent({
  props: {
    modelValue: { type: String, default: '' },
  },
  emits: ['update:modelValue'],
  data(): SendTestEmailState {
    return {
      recipient: '',
      sending: false,
      ready: false,
      reason: '',
      result: null,
    };
  },
  mounted() {
    this.refreshStatus();

    if ($) {
      $(document).on('ajaxComplete.missivus', this.onAjaxComplete);
    }
  },
  unmounted() {
    if ($) {
      $(document).off('ajaxComplete.missivus');
    }
  },
  methods: {
    translate,
    onAjaxComplete(event: unknown, xhr: unknown, settings: { url?: string, data?: unknown }) {
      // Only a settings save can change the answer, and matching on it also keeps this from
      // reacting to the status request it triggers itself.
      const url = (settings && settings.url) || '';
      const data = (settings && typeof settings.data === 'string') ? settings.data : '';

      if (`${url}${data}`.indexOf('setSystemSettings') !== -1) {
        this.refreshStatus();
      }
    },
    refreshStatus() {
      AjaxHelper.fetch<{ ready: boolean; reason: string }>({
        method: 'Missivus.getTestEmailStatus',
      }).then((response) => {
        this.ready = !!(response && response.ready);
        this.reason = (response && response.reason) ? response.reason : '';
      }).catch(() => {
        this.ready = false;
        this.reason = translate('Missivus_TestEmailNotReady');
      });
    },
    send() {
      if (this.sending || !this.ready) {
        return;
      }

      this.sending = true;
      this.result = null;

      AjaxHelper.post<{ success: boolean; message: string }>({
        method: 'Missivus.sendTestEmail',
      }, {
        to: this.recipient,
      }).then((response) => {
        const success = !!(response && response.success);

        this.result = {
          success,
          message: (response && response.message)
            ? response.message
            : translate(success ? 'Missivus_TestEmailSent' : 'Missivus_TestEmailFailed'),
        };
      }).catch((error) => {
        this.result = {
          success: false,
          message: (error && error.message) ? error.message : translate('Missivus_TestEmailFailed'),
        };
      }).then(() => {
        this.sending = false;
      });
    },
  },
});
</script>
