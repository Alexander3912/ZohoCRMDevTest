<template>
    <div class="max-w-lg mx-auto mt-10 p-8 bg-white shadow-xl rounded-xl border border-gray-200">
        <h1 class="text-3xl font-bold mb-6 text-gray-900 text-center">
            Интеграция Zoho CRM
        </h1>
        <form @submit.prevent="submitForm" class="space-y-5">
            <div v-for="(field, key) in formData" :key="key">
                <label :for="key" class="block text-sm font-semibold text-gray-800">
                    {{ field.label }}
                </label>
                <input
                    :id="key"
                    v-model="field.value"
                    :type="field.type"
                    class="w-full mt-1 p-3 border rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all"
                    :class="{ 'border-red-500': errors[key] }"
                    :placeholder="field.placeholder"
                    @blur="validateField(key)"
                />
                <p v-if="errors[key]" class="text-red-500 text-sm mt-1">{{ errors[key] }}</p>
            </div>

            <p v-if="serverError" class="text-red-600 text-sm text-center mt-2 bg-red-100 p-2 rounded-lg">
                {{ serverError }}
            </p>

            <button
                type="submit"
                :disabled="loading"
                class="w-full flex justify-center items-center bg-gradient-to-r from-blue-500 to-blue-600 text-white py-3 px-4 rounded-lg shadow-md hover:from-blue-600 hover:to-blue-700 transition-all focus:outline-none focus:ring-2 focus:ring-blue-400 disabled:opacity-50 disabled:cursor-not-allowed"
            >
                <svg v-if="loading" class="animate-spin h-5 w-5 mr-2 text-white" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0116 0H4z"></path>
                </svg>
                <span v-if="loading">Отправка...</span>
                <span v-else>Создать запись</span>
            </button>
        </form>

        <transition name="fade">
            <div v-if="successMessage" class="mt-6 bg-green-100 text-green-800 text-center p-3 rounded-lg shadow-sm">
                {{ successMessage }}
            </div>
        </transition>
    </div>
</template>

<script>
import axios from "axios";

export default {
    name: "ZohoForm",
    data() {
        return {
            formData: {
                dealName: { value: "", label: "Название сделки", type: "text", placeholder: "Введите название сделки" },
                dealStage: { value: "", label: "Этап сделки", type: "text", placeholder: "Введите этап сделки" },
                accountName: { value: "", label: "Название аккаунта", type: "text", placeholder: "Введите название аккаунта" },
                accountWebsite: { value: "", label: "Веб-сайт аккаунта", type: "url", placeholder: "Введите URL веб-сайта" },
                accountPhone: { value: "", label: "Телефон аккаунта", type: "tel", placeholder: "Введите номер телефона" }
            },
            errors: {},
            loading: false,
            serverError: "",
            successMessage: ""
        };
    },
    methods: {
        validateField(key) {
            const value = this.formData[key].value;
            let error = "";

            if (!value) {
                error = "Это поле обязательно.";
            } else if (key === "accountWebsite" && !/^https?:\/\/[^\s$.?#].[^\s]*$/.test(value)) {
                error = "Некорректный формат URL.";
            } else if (key === "accountPhone" && !/^\+?\d{10,15}$/.test(value)) {
                error = "Некорректный номер телефона (пример: +1234567890).";
            }

            this.errors[key] = error;
        },
        async submitForm() {
            this.serverError = "";
            this.successMessage = "";

            Object.keys(this.formData).forEach(this.validateField);

            if (Object.values(this.errors).some((err) => err)) {
                alert("Исправьте ошибки в форме.");
                return;
            }

            this.loading = true;
            try {
                const formattedData = Object.fromEntries(
                    Object.entries(this.formData).map(([key, field]) => [key, field.value])
                );

                const response = await axios.post("/zoho/create-deal", formattedData);
                this.successMessage = response.data.message;
                console.log(response.data);

                Object.keys(this.formData).forEach((key) => {
                    this.formData[key].value = "";
                });
            } catch (error) {
                this.serverError = error.response?.data?.error || "Ошибка при создании записи.";
                console.error(error.response?.data);
            } finally {
                this.loading = false;
            }
        }
    }
};
</script>

<style scoped>
.fade-enter-active, .fade-leave-active {
    transition: opacity 0.5s;
}
.fade-enter, .fade-leave-to {
    opacity: 0;
}

body {
    background-color: #f9fafb;
}
</style>