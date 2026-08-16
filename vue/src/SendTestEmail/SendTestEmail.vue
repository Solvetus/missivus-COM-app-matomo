<!--
  Missivus — send Matomo email through the Microsoft Graph API.
  @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later

  SystemSettings has no button primitive, so this component is mounted as a field's
  customFieldComponent. It stores nothing: modelValue is accepted so Matomo's field wrapper is
  happy, and ignored.

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
        :disabled="sending"
        v-model="recipient"
      />
      <button
        type="button"
        class="btn"
        :disabled="sending"
        @click.prevent="send"
      >
        {{ sending ? translate('Missivus_SendingTestEmail') : translate('Missivus_SendTestEmail') }}
      </button>
    </div>

    <div
      v-if="result"
      class="missivusTestEmailResult"
      :class="result.success ? 'success' : 'failure'"
    >{{ result.message }}</div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import { AjaxHelper, translate } from 'CoreHome';

interface SendTestEmailState {
  recipient: string;
  sending: boolean;
  result: { success: boolean; message: string } | null;
}

export default defineComponent({
  props: {
    modelValue: { type: String, default: '' },
  },
  emits: ['update:modelValue'],
  data(): SendTestEmailState {
    return {
      recipient: '',
      sending: false,
      result: null,
    };
  },
  methods: {
    translate,
    send() {
      if (this.sending) {
        return;
      }

      this.sending = true;
      this.result = null;

      AjaxHelper.fetch<{ success: boolean; message: string }>({
        method: 'Missivus.sendTestEmail',
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
