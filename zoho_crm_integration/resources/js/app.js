import './bootstrap';
import { createApp } from 'vue';
import axios from 'axios';

axios.defaults.headers.common['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

import ZohoForm from './components/ZohoForm.vue';

const app = createApp({});
app.component('zoho-form', ZohoForm);
app.mount('#app');