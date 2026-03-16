<x-admin::layouts>
    <x-slot:title>
        @lang('shopify::app.shopify.credential.index.title')
    </x-slot>

    <v-credential>
        <div class="flex justify-between items-center">
            <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                @lang('shopify::app.shopify.credential.index.title')
            </p>

            <div class="flex gap-x-2.5 items-center">
                <!-- Create User Button -->
                @if (bouncer()->hasPermission('shopify.credentials.create'))
                    <button
                        type="button"
                        class="primary-button"
                    >
                        @lang('shopify::app.shopify.credential.index.create')
                    </button>
                @endif
            </div>
        </div>

        <!-- DataGrid Shimmer -->
        <x-admin::shimmer.datagrid />
    </v-credential>
    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-credential-template"
        >
            <div class="flex justify-between items-center">
                <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                    @lang('shopify::app.shopify.credential.index.title')
                </p>

                <div class="flex gap-x-2.5 items-center">
                    <!-- User Create Button -->
                    @if (bouncer()->hasPermission('shopify.credentials.create'))
                        <button
                            type="button"
                            class="primary-button"
                            @click="$refs.credentialCreateModal.open()"
                        >
                            @lang('shopify::app.shopify.credential.index.create')
                        </button>
                    @endif
                </div>
            </div>
            <!-- Datagrid -->
            <x-admin::datagrid :src="route('shopify.credentials.index')" ref="datagrid" class="mb-8"/>
            <!-- Modal Form -->
            <x-admin::form
                v-slot="{ meta, errors, handleSubmit }"
                as="div"
                ref="modalForm"
            >
                <form
                    @submit="handleSubmit($event, create)"
                    ref="credentialCreateForm"
                >
                    <!-- User Create Modal -->
                    <x-admin::modal ref="credentialCreateModal">
                        <!-- Modal Header -->
                        <x-slot:header>
                             <p class="text-lg text-gray-800 dark:text-white font-bold">
                                @lang('shopify::app.shopify.credential.index.create')
                            </p>

                        </x-slot>

                        <!-- Modal Content -->
                        <x-slot:content>
                            <!-- Shop URL -->
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label class="required">
                                    @lang('shopify::app.shopify.credential.index.url')
                                </x-admin::form.control-group.label>
                                <x-admin::form.control-group.control
                                    type="text"
                                    id="shopUrl"
                                    name="shopUrl"
                                    rules="required"
                                    :label="trans('shopify::app.shopify.credential.index.url')"
                                    :placeholder="trans('shopify::app.shopify.credential.index.shopifyurlplaceholder')"
                                />

                                <x-admin::form.control-group.error control-name="shopUrl" />
                            </x-admin::form.control-group>

                            <!-- Authentication Method -->
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label class="required">
                                    @lang('shopify::app.shopify.credential.index.authMethod')
                                </x-admin::form.control-group.label>

                                <select
                                    v-model="authMode"
                                    class="w-full rounded-md border px-3 py-2.5 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-cherry-800 dark:bg-cherry-900 dark:text-gray-300"
                                >
                                    <option value="access_token">@lang('shopify::app.shopify.credential.index.authAccessToken')</option>
                                    <option value="client_credentials">@lang('shopify::app.shopify.credential.index.authClientCredentials')</option>
                                </select>
                            </x-admin::form.control-group>

                            <!-- Access Token (for Custom Apps) -->
                            <x-admin::form.control-group v-if="authMode === 'access_token'">
                                <x-admin::form.control-group.label class="required">
                                    @lang('shopify::app.shopify.credential.index.accessToken')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="password"
                                    id="accessToken"
                                    name="accessToken"
                                    ::rules="authMode === 'access_token' ? 'required' : ''"
                                    :label="trans('shopify::app.shopify.credential.index.accessToken')"
                                    :placeholder="trans('shopify::app.shopify.credential.index.accessTokenPlaceholder')"
                                />

                                <x-admin::form.control-group.error control-name="accessToken" />
                            </x-admin::form.control-group>

                            <!-- Client ID (for Organization Apps) -->
                            <x-admin::form.control-group v-if="authMode === 'client_credentials'">
                                <x-admin::form.control-group.label class="required">
                                    @lang('shopify::app.shopify.credential.index.clientId')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="text"
                                    id="clientId"
                                    name="clientId"
                                    ::rules="authMode === 'client_credentials' ? 'required' : ''"
                                    :label="trans('shopify::app.shopify.credential.index.clientId')"
                                    :placeholder="trans('shopify::app.shopify.credential.index.clientIdPlaceholder')"
                                />

                                <x-admin::form.control-group.error control-name="clientId" />
                            </x-admin::form.control-group>

                            <!-- Client Secret (for Organization Apps) -->
                            <x-admin::form.control-group v-if="authMode === 'client_credentials'">
                                <x-admin::form.control-group.label class="required">
                                    @lang('shopify::app.shopify.credential.index.clientSecret')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="password"
                                    id="clientSecret"
                                    name="clientSecret"
                                    ::rules="authMode === 'client_credentials' ? 'required' : ''"
                                    :label="trans('shopify::app.shopify.credential.index.clientSecret')"
                                    :placeholder="trans('shopify::app.shopify.credential.index.clientSecretPlaceholder')"
                                />

                                <x-admin::form.control-group.error control-name="clientSecret" />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group class="mb-4">
                                <x-admin::form.control-group.label class="required">
                                    @lang('shopify::app.shopify.credential.index.apiVersion')
                                </x-admin::form.control-group.label>

                                @php
                                    $apiVersion = json_encode($apiVersion, true);
                                @endphp

                                <x-admin::form.control-group.control
                                    type="select"
                                    id="apiVersion"
                                    disabled="disabled"
                                    name="apiVersion"
                                    rules="required"
                                    :label="trans('shopify::app.shopify.credential.index.apiVersion')"
                                    :placeholder="trans('shopify::app.shopify.credential.index.apiVersion')"
                                    :options="$apiVersion"
                                    value="2026-01"
                                    track-by="id"
                                    label-by="name"
                                >
                                </x-admin::form.control-group.control>
                                <x-admin::form.control-group.error control-name="apiVersion" />
                            </x-admin::form.control-group>
                        </x-slot>

                        <!-- Modal Footer -->
                        <x-slot:footer>
                            <div class="flex gap-x-2.5 items-center">
                                <button
                                    type="submit"
                                    class="primary-button"
                                >
                                    @lang('shopify::app.shopify.credential.index.save')
                                </button>
                            </div>
                        </x-slot>
                    </x-admin::modal>
                </form>
            </x-admin::form>
        </script>

        <script type="module">
            app.component('v-credential', {
                template: '#v-credential-template',

                data() {
                    return {
                        authMode: 'access_token',
                    };
                },

                methods: {
                    create(params, { setErrors }) {
                        let formData = new FormData(this.$refs.credentialCreateForm);

                        this.$axios.post("{{ route('shopify.credentials.store') }}", formData)
                            .then((response) => {
                                window.location.href = response.data.redirect_url;
                            })
                            .catch(error => {
                                if (error.response.status == 422) {
                                    setErrors(error.response.data.errors);
                                }
                            });
                    },
                }
            })
        </script>
    @endPushOnce
</x-admin::layouts>
