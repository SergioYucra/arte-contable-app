<script setup>
import { ref, computed, onMounted } from 'vue';
import Fuse from 'fuse.js';
import {
    Search,
    Shield,
    Users,
    Check,
    X,
    UserCheck,
    RefreshCw,
    AlertCircle,
    UserX,
    RotateCcw,
    Mail,
    Send,
} from 'lucide-vue-next';
import BaseModal from './ui/BaseModal.vue';
import BaseAlert from './ui/BaseAlert.vue';

const props = defineProps({
    initialUsers: {
        type: Array,
        required: true,
    },
    availableRoles: {
        type: Array,
        required: true,
    },
    updateRoleUrlTemplate: {
        type: String,
        required: true,
    },
    deleteUserUrlTemplate: {
        type: String,
        default: '',
    },
    restoreUserUrlTemplate: {
        type: String,
        default: '',
    },
    resendVerificationUrlTemplate: {
        type: String,
        default: '',
    },
});

// Estado reactivo principal
const users = ref([...props.initialUsers]);
const searchQuery = ref('');
const selectedRoleFilter = ref('ALL');
const selectedStatusFilter = ref('ACTIVE'); // 'ACTIVE', 'DEACTIVATED', 'ALL'

// Modales y estados de acción
const isRoleModalOpen = ref(false);
const activeUser = ref(null);
const targetRole = ref('');
const isSubmittingRole = ref(false);

const isDeactivateModalOpen = ref(false);
const userToDeactivate = ref(null);
const isSubmittingDeactivate = ref(false);

const isRestoreModalOpen = ref(false);
const userToRestore = ref(null);
const isSubmittingRestore = ref(false);

const resendingVerificationId = ref(null);

// Notificaciones y errores
const notification = ref(null);
const modalError = ref(null);

// Inicializar Fuse.js para búsqueda difusa en memoria
let fuse = null;

function setupFuse() {
    fuse = new Fuse(users.value, {
        keys: ['name', 'email', 'role'],
        threshold: 0.3,
        ignoreLocation: true,
    });
}

onMounted(() => {
    setupFuse();
});

// Conteos por estado
const statusCounts = computed(() => {
    const active = users.value.filter((u) => !u.deleted_at).length;
    const deactivated = users.value.filter((u) => !!u.deleted_at).length;
    return {
        ALL: users.value.length,
        ACTIVE: active,
        DEACTIVATED: deactivated,
    };
});

// Conteo por roles según el estado seleccionado
const roleCounts = computed(() => {
    let baseList = users.value;
    if (selectedStatusFilter.value === 'ACTIVE') {
        baseList = baseList.filter((u) => !u.deleted_at);
    } else if (selectedStatusFilter.value === 'DEACTIVATED') {
        baseList = baseList.filter((u) => !!u.deleted_at);
    }

    const counts = { ALL: baseList.length };
    props.availableRoles.forEach((role) => {
        counts[role] = baseList.filter((u) => u.role === role).length;
    });
    return counts;
});

// Lista filtrada reactiva (Estado + Rol + Fuse.js)
const filteredUsers = computed(() => {
    let result = users.value;

    // 1. Filtrar por estado activo/desactivado
    if (selectedStatusFilter.value === 'ACTIVE') {
        result = result.filter((u) => !u.deleted_at);
    } else if (selectedStatusFilter.value === 'DEACTIVATED') {
        result = result.filter((u) => !!u.deleted_at);
    }

    // 2. Filtrar por rol si no es ALL
    if (selectedRoleFilter.value !== 'ALL') {
        result = result.filter((u) => u.role === selectedRoleFilter.value);
    }

    // 3. Si hay texto en el buscador, aplicar Fuse.js
    if (searchQuery.value.trim() !== '') {
        const queryFuse = new Fuse(result, {
            keys: ['name', 'email', 'role'],
            threshold: 0.3,
            ignoreLocation: true,
        });
        return queryFuse.search(searchQuery.value.trim()).map((res) => res.item);
    }

    return result;
});

// Etiquetas legibles en español
const ROLE_LABELS = {
    SuperAdmin: 'Superadministrador',
    Admin: 'Administrador',
    Manager: 'Coordinador',
    User: 'Estudiante',
};

const ROLE_DESCRIPTIONS = {
    SuperAdmin: 'Acceso total y configuración del sistema',
    Admin: 'Gestión de usuarios y revisión de actividad',
    Manager: 'Acceso a reportes y seguimiento',
    User: 'Acceso de estudio sin permisos de gestión',
};

function roleLabel(role) {
    return ROLE_LABELS[role] || role;
}

function getRoleBadgeClass(role) {
    switch (role) {
        case 'SuperAdmin':
            return 'bg-purple-500/10 text-purple-700 dark:text-purple-300 border-purple-500/20';
        case 'Admin':
            return 'bg-primary/10 text-primary border-primary/20';
        case 'Manager':
            return 'bg-sky-500/10 text-sky-700 dark:text-sky-300 border-sky-500/20';
        default:
            return 'bg-slate-500/10 text-slate-700 dark:text-slate-300 border-slate-500/20';
    }
}

// -------------------------------------------------------------
// Acciones: Cambio de Rol
// -------------------------------------------------------------
function openRoleModal(user) {
    activeUser.value = user;
    targetRole.value = user.role;
    modalError.value = null;
    isRoleModalOpen.value = true;
}

async function saveRoleChange() {
    if (!activeUser.value || targetRole.value === activeUser.value.role) {
        isRoleModalOpen.value = false;
        return;
    }

    isSubmittingRole.value = true;
    modalError.value = null;

    const url = props.updateRoleUrlTemplate.replace(':id', activeUser.value.id);

    try {
        const response = await window.axios.patch(url, {
            role: targetRole.value,
        });

        const userIndex = users.value.findIndex((u) => u.id === activeUser.value.id);
        if (userIndex !== -1) {
            users.value[userIndex].role = targetRole.value;
        }

        setupFuse();

        notification.value = {
            type: 'success',
            title: 'Rol actualizado',
            message: response.data.message || `El rol fue actualizado a ${targetRole.value}.`,
        };

        isRoleModalOpen.value = false;
    } catch (error) {
        modalError.value = error.response?.data?.message || 'Ocurrió un error al intentar cambiar el rol.';
    } finally {
        isSubmittingRole.value = false;
    }
}

// -------------------------------------------------------------
// Acciones: Desactivar Usuario (SoftDelete)
// -------------------------------------------------------------
function confirmDeactivateUser(user) {
    userToDeactivate.value = user;
    modalError.value = null;
    isDeactivateModalOpen.value = true;
}

async function executeDeactivateUser() {
    if (!userToDeactivate.value) return;

    isSubmittingDeactivate.value = true;
    modalError.value = null;

    const url = props.deleteUserUrlTemplate.replace(':id', userToDeactivate.value.id);

    try {
        const response = await window.axios.delete(url);

        const userIndex = users.value.findIndex((u) => u.id === userToDeactivate.value.id);
        if (userIndex !== -1) {
            users.value[userIndex].deleted_at = new Date().toISOString();
            users.value[userIndex].is_active = false;
        }

        setupFuse();

        notification.value = {
            type: 'success',
            title: 'Usuario desactivado',
            message: response.data.message || `La cuenta de ${userToDeactivate.value.name} ha sido desactivada.`,
        };

        isDeactivateModalOpen.value = false;
    } catch (error) {
        modalError.value = error.response?.data?.message || 'No fue posible desactivar al usuario.';
    } finally {
        isSubmittingDeactivate.value = false;
    }
}

// -------------------------------------------------------------
// Acciones: Reactivar Usuario
// -------------------------------------------------------------
function confirmRestoreUser(user) {
    userToRestore.value = user;
    modalError.value = null;
    isRestoreModalOpen.value = true;
}

async function executeRestoreUser() {
    if (!userToRestore.value) return;

    isSubmittingRestore.value = true;
    modalError.value = null;

    const url = props.restoreUserUrlTemplate.replace(':id', userToRestore.value.id);

    try {
        const response = await window.axios.post(url);

        const userIndex = users.value.findIndex((u) => u.id === userToRestore.value.id);
        if (userIndex !== -1) {
            users.value[userIndex].deleted_at = null;
            users.value[userIndex].is_active = true;
        }

        setupFuse();

        notification.value = {
            type: 'success',
            title: 'Usuario reactivado',
            message: response.data.message || `La cuenta de ${userToRestore.value.name} ha sido reactivada.`,
        };

        isRestoreModalOpen.value = false;
    } catch (error) {
        modalError.value = error.response?.data?.message || 'No fue posible reactivar al usuario.';
    } finally {
        isSubmittingRestore.value = false;
    }
}

// -------------------------------------------------------------
// Acciones: Reenviar enlace de verificación de correo (N6)
// -------------------------------------------------------------
async function handleResendVerification(user) {
    if (!props.resendVerificationUrlTemplate) return;

    resendingVerificationId.value = user.id;

    const url = props.resendVerificationUrlTemplate.replace(':id', user.id);

    try {
        const response = await window.axios.post(url);

        notification.value = {
            type: 'success',
            title: 'Enlace enviado',
            message: response.data.message || `Se ha reenviado el enlace de verificación a ${user.email}.`,
        };
    } catch (error) {
        notification.value = {
            type: 'error',
            title: 'Error de reenvío',
            message: error.response?.data?.message || 'No fue posible enviar el correo de verificación.',
        };
    } finally {
        resendingVerificationId.value = null;
    }
}
</script>

<template>
    <div class="space-y-6">
        <!-- Notificación global superior -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 -translate-y-2"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0 -translate-y-2"
        >
            <BaseAlert
                v-if="notification"
                :type="notification.type"
                :title="notification.title"
                dismissible
                @dismiss="notification = null"
            >
                {{ notification.message }}
            </BaseAlert>
        </Transition>

        <!-- Barra de herramientas: Tabs de estado, búsqueda instantánea y filtros de roles (UX-07 390px responsive) -->
        <div class="bg-surface border border-border rounded-brand p-4 shadow-xs space-y-4">
            <!-- Pestañas de estado (Activos / Desactivados / Todos) -->
            <div class="flex items-center gap-2 border-b border-border pb-3 overflow-x-auto">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand text-xs font-semibold transition-all"
                    :class="selectedStatusFilter === 'ACTIVE'
                        ? 'bg-primary text-primary-foreground shadow-xs'
                        : 'bg-background text-muted hover:text-foreground border border-border'"
                    @click="selectedStatusFilter = 'ACTIVE'"
                >
                    <UserCheck class="h-3.5 w-3.5" />
                    Activos ({{ statusCounts.ACTIVE }})
                </button>

                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand text-xs font-semibold transition-all"
                    :class="selectedStatusFilter === 'DEACTIVATED'
                        ? 'bg-rose-600 text-white shadow-xs'
                        : 'bg-background text-muted hover:text-foreground border border-border'"
                    @click="selectedStatusFilter = 'DEACTIVATED'"
                >
                    <UserX class="h-3.5 w-3.5" />
                    Desactivados ({{ statusCounts.DEACTIVATED }})
                </button>

                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand text-xs font-semibold transition-all"
                    :class="selectedStatusFilter === 'ALL'
                        ? 'bg-secondary text-secondary-foreground shadow-xs'
                        : 'bg-background text-muted hover:text-foreground border border-border'"
                    @click="selectedStatusFilter = 'ALL'"
                >
                    <Users class="h-3.5 w-3.5" />
                    Todos ({{ statusCounts.ALL }})
                </button>
            </div>

            <!-- Búsqueda y Filtros de Roles con flex-wrap para evitar desbordes a 390px -->
            <div class="flex flex-col md:flex-row gap-3 items-stretch md:items-center justify-between">
                <!-- Buscador en tiempo real -->
                <div class="relative w-full md:w-80">
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted pointer-events-none" />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Buscar por nombre, email o rol..."
                        class="w-full pl-9 pr-8 py-2 bg-background border border-border rounded-brand text-xs sm:text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                    />
                    <button
                        v-if="searchQuery"
                        type="button"
                        class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted hover:text-foreground p-1"
                        @click="searchQuery = ''"
                        title="Limpiar búsqueda"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>

                <!-- Filtros por Rol tipo Pills con flex-wrap -->
                <div class="flex flex-wrap items-center gap-1.5 w-full md:w-auto">
                    <button
                        type="button"
                        class="px-2.5 py-1 rounded-full text-xs font-medium transition-all"
                        :class="selectedRoleFilter === 'ALL'
                            ? 'bg-primary text-primary-foreground shadow-xs'
                            : 'bg-background text-muted hover:text-foreground border border-border'"
                        @click="selectedRoleFilter = 'ALL'"
                    >
                        Todos los roles ({{ roleCounts.ALL }})
                    </button>

                    <button
                        v-for="role in availableRoles"
                        :key="role"
                        type="button"
                        class="px-2.5 py-1 rounded-full text-xs font-medium transition-all"
                        :class="selectedRoleFilter === role
                            ? 'bg-primary text-primary-foreground shadow-xs'
                            : 'bg-background text-muted hover:text-foreground border border-border'"
                        @click="selectedRoleFilter = role"
                    >
                        {{ roleLabel(role) }} ({{ roleCounts[role] || 0 }})
                    </button>
                </div>
            </div>
        </div>

        <!-- Resumen de resultados -->
        <div class="flex items-center justify-between text-xs text-muted px-1">
            <span class="flex items-center gap-1.5">
                <Users class="h-3.5 w-3.5" />
                Mostrando <strong class="text-foreground">{{ filteredUsers.length }}</strong> de {{ users.length }} usuarios
            </span>
        </div>

        <!-- Tabla interactiva con contenedor overflow-x-auto estricto para 390px (UX-07) -->
        <div class="bg-surface border border-border rounded-brand shadow-xs overflow-hidden">
            <div class="w-full overflow-x-auto">
                <table class="min-w-[720px] w-full text-left text-sm divide-y divide-border">
                    <thead class="bg-background text-xs uppercase font-semibold text-muted tracking-wider">
                        <tr>
                            <th class="px-5 py-3.5">Usuario</th>
                            <th class="px-5 py-3.5">Nivel de acceso</th>
                            <th class="px-5 py-3.5">Verificación</th>
                            <th class="px-5 py-3.5 hidden lg:table-cell">Registrado</th>
                            <th class="px-5 py-3.5 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border bg-surface">
                        <tr
                            v-for="user in filteredUsers"
                            :key="user.id"
                            class="hover:bg-background/60 transition-colors"
                            :class="{ 'opacity-65 bg-background/30': !!user.deleted_at }"
                        >
                            <!-- Columna Usuario -->
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <div
                                        class="h-9 w-9 shrink-0 rounded-full flex items-center justify-center font-semibold text-sm border"
                                        :class="user.deleted_at
                                            ? 'bg-muted/10 text-muted border-border'
                                            : 'bg-primary/10 text-primary border-primary/20'"
                                    >
                                        {{ user.name.charAt(0).toUpperCase() }}
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-foreground truncate">{{ user.name }}</span>
                                            <span
                                                v-if="user.deleted_at"
                                                class="px-2 py-0.2 rounded-full text-[10px] font-bold uppercase tracking-wider bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20"
                                            >
                                                Desactivado
                                            </span>
                                        </div>
                                        <div class="text-xs text-muted truncate">{{ user.email }}</div>
                                    </div>
                                </div>
                            </td>

                            <!-- Columna Rol Actual -->
                            <td class="px-5 py-4 whitespace-nowrap">
                                <span
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border"
                                    :class="getRoleBadgeClass(user.role)"
                                >
                                    <Shield class="h-3 w-3" />
                                    {{ roleLabel(user.role) }}
                                </span>
                            </td>

                            <!-- Columna Estado de Verificación (N6) -->
                            <td class="px-5 py-4 whitespace-nowrap">
                                <div class="flex items-center gap-2">
                                    <span
                                        v-if="user.email_verified_at"
                                        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20"
                                    >
                                        <Check class="h-3 w-3" />
                                        Verificado
                                    </span>
                                    <div v-else class="flex items-center gap-2">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-500/20">
                                            <AlertCircle class="h-3 w-3" />
                                            Pendiente
                                        </span>
                                        <button
                                            v-if="!user.deleted_at && resendVerificationUrlTemplate"
                                            type="button"
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-brand text-[10px] font-medium bg-surface border border-border text-muted hover:text-primary hover:border-primary/30 transition-all disabled:opacity-50"
                                            :disabled="resendingVerificationId === user.id"
                                            title="Reenviar correo de verificación"
                                            @click="handleResendVerification(user)"
                                        >
                                            <RefreshCw v-if="resendingVerificationId === user.id" class="h-3 w-3 animate-spin text-primary" />
                                            <Send v-else class="h-3 w-3" />
                                            Reenviar
                                        </button>
                                    </div>
                                </div>
                            </td>

                            <!-- Columna Fecha -->
                            <td class="px-5 py-4 text-xs text-muted whitespace-nowrap hidden lg:table-cell">
                                {{ user.created_at }}
                            </td>

                            <!-- Columna Acciones (UX-05, UX-07) -->
                            <td class="px-5 py-4 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1.5">
                                    <!-- Si el usuario está activo -->
                                    <template v-if="!user.deleted_at">
                                        <button
                                            v-if="user.can_edit !== false"
                                            type="button"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-brand text-xs font-medium bg-surface border border-border text-foreground hover:bg-background hover:border-primary/40 focus:ring-2 focus:ring-primary/20 transition-all"
                                            @click="openRoleModal(user)"
                                            title="Modificar rol y nivel de acceso"
                                        >
                                            <Shield class="h-3.5 w-3.5 text-primary" />
                                            Nivel
                                        </button>

                                        <button
                                            v-if="user.can_edit !== false"
                                            type="button"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-brand text-xs font-medium bg-surface border border-border text-rose-600 dark:text-rose-400 hover:bg-rose-500/10 hover:border-rose-500/40 focus:ring-2 focus:ring-rose-500/20 transition-all"
                                            @click="confirmDeactivateUser(user)"
                                            title="Desactivar cuenta del usuario"
                                        >
                                            <UserX class="h-3.5 w-3.5" />
                                            Desactivar
                                        </button>

                                        <span
                                            v-else
                                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-brand text-xs text-muted bg-background border border-border/80 cursor-not-allowed"
                                            title="Cuenta protegida de Superadministrador"
                                        >
                                            <Shield class="h-3 w-3 text-muted/60" />
                                            Protegido
                                        </span>
                                    </template>

                                    <!-- Si el usuario está desactivado (SoftDelete) -->
                                    <template v-else>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-brand text-xs font-semibold bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/20 focus:ring-2 focus:ring-emerald-500/20 transition-all"
                                            @click="confirmRestoreUser(user)"
                                            title="Reactivar acceso al sistema"
                                        >
                                            <RotateCcw class="h-3.5 w-3.5" />
                                            Reactivar
                                        </button>
                                    </template>
                                </div>
                            </td>
                        </tr>

                        <!-- Estado vacío si los filtros no arrojan resultados -->
                        <tr v-if="filteredUsers.length === 0">
                            <td colspan="5" class="px-6 py-12 text-center">
                                <div class="max-w-xs mx-auto space-y-3">
                                    <div class="h-10 w-10 mx-auto rounded-full bg-muted/10 text-muted flex items-center justify-center">
                                        <Search class="h-5 w-5" />
                                    </div>
                                    <h4 class="text-sm font-semibold text-foreground">No se encontraron usuarios</h4>
                                    <p class="text-xs text-muted">
                                        No hay usuarios que coincidan con "{{ searchQuery }}" en el filtro actual.
                                    </p>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-brand text-xs font-medium bg-primary text-primary-foreground hover:bg-primary-hover transition-colors"
                                        @click="searchQuery = ''; selectedRoleFilter = 'ALL'; selectedStatusFilter = 'ALL'"
                                    >
                                        Restablecer filtros
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal 1: Cambiar el nivel de acceso -->
        <BaseModal
            v-model="isRoleModalOpen"
            title="Modificar nivel de acceso"
            max-width="md"
        >
            <div v-if="activeUser" class="space-y-4">
                <p class="text-xs text-muted">
                    Selecciona el nuevo nivel de acceso para <strong class="text-foreground">{{ activeUser.name }}</strong> ({{ activeUser.email }}):
                </p>

                <BaseAlert v-if="modalError" type="error" title="Operación no permitida">
                    {{ modalError }}
                </BaseAlert>

                <div class="space-y-2">
                    <label
                        v-for="role in availableRoles"
                        :key="role"
                        class="flex items-center justify-between p-3 rounded-brand border cursor-pointer transition-all"
                        :class="targetRole === role
                            ? 'border-primary bg-primary/5 ring-1 ring-primary/30'
                            : 'border-border hover:bg-background'"
                    >
                        <div class="flex items-center gap-3">
                            <input
                                v-model="targetRole"
                                type="radio"
                                :value="role"
                                class="text-primary focus:ring-primary border-border"
                            />
                            <div>
                                <span class="block text-sm font-semibold text-foreground">{{ roleLabel(role) }}</span>
                                <span class="block text-xs text-muted">{{ ROLE_DESCRIPTIONS[role] || '' }}</span>
                            </div>
                        </div>

                        <span
                            class="px-2 py-0.5 rounded-full text-[10px] font-semibold border"
                            :class="getRoleBadgeClass(role)"
                        >
                            {{ roleLabel(role) }}
                        </span>
                    </label>
                </div>
            </div>

            <template #footer="{ close }">
                <button
                    type="button"
                    class="px-4 py-2 text-xs font-medium rounded-brand border border-border text-muted hover:text-foreground hover:bg-surface transition-colors"
                    :disabled="isSubmittingRole"
                    @click="close"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-brand bg-primary text-primary-foreground hover:bg-primary-hover shadow-xs focus:ring-2 focus:ring-primary/20 transition-all disabled:opacity-50"
                    :disabled="isSubmittingRole || targetRole === activeUser?.role"
                    @click="saveRoleChange"
                >
                    <RefreshCw v-if="isSubmittingRole" class="h-3.5 w-3.5 animate-spin" />
                    <Check v-else class="h-3.5 w-3.5" />
                    {{ isSubmittingRole ? 'Guardando...' : 'Confirmar cambio' }}
                </button>
            </template>
        </BaseModal>

        <!-- Modal 2: Confirmar Desactivación (SoftDelete) -->
        <BaseModal
            v-model="isDeactivateModalOpen"
            title="Desactivar cuenta de usuario"
            max-width="md"
        >
            <div v-if="userToDeactivate" class="space-y-3">
                <p class="text-sm text-foreground">
                    ¿Estás seguro de que deseas desactivar la cuenta de <strong class="font-semibold">{{ userToDeactivate.name }}</strong> ({{ userToDeactivate.email }})?
                </p>
                <p class="text-xs text-muted leading-relaxed">
                    El usuario no podrá iniciar sesión en la plataforma. Esta acción realiza un borrado lógico (SoftDelete), por lo que sus registros históricos se conservarán y la cuenta podrá ser reactivada en cualquier momento.
                </p>

                <BaseAlert v-if="modalError" type="error" title="No se pudo desactivar la cuenta">
                    {{ modalError }}
                </BaseAlert>
            </div>

            <template #footer="{ close }">
                <button
                    type="button"
                    class="px-4 py-2 text-xs font-medium rounded-brand border border-border text-muted hover:text-foreground hover:bg-surface transition-colors"
                    :disabled="isSubmittingDeactivate"
                    @click="close"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-brand bg-rose-600 text-white hover:bg-rose-700 shadow-xs focus:ring-2 focus:ring-rose-500/20 transition-all disabled:opacity-50"
                    :disabled="isSubmittingDeactivate"
                    @click="executeDeactivateUser"
                >
                    <RefreshCw v-if="isSubmittingDeactivate" class="h-3.5 w-3.5 animate-spin" />
                    <UserX v-else class="h-3.5 w-3.5" />
                    {{ isSubmittingDeactivate ? 'Desactivando...' : 'Sí, desactivar cuenta' }}
                </button>
            </template>
        </BaseModal>

        <!-- Modal 3: Confirmar Reactivación -->
        <BaseModal
            v-model="isRestoreModalOpen"
            title="Reactivar cuenta de usuario"
            max-width="md"
        >
            <div v-if="userToRestore" class="space-y-3">
                <p class="text-sm text-foreground">
                    ¿Deseas reactivar el acceso para <strong class="font-semibold">{{ userToRestore.name }}</strong> ({{ userToRestore.email }})?
                </p>
                <p class="text-xs text-muted leading-relaxed">
                    La cuenta volverá a estar activa y el usuario podrá iniciar sesión con sus credenciales habituales. La reactivación quedará asentada en la bitácora de auditoría.
                </p>

                <BaseAlert v-if="modalError" type="error" title="No se pudo reactivar la cuenta">
                    {{ modalError }}
                </BaseAlert>
            </div>

            <template #footer="{ close }">
                <button
                    type="button"
                    class="px-4 py-2 text-xs font-medium rounded-brand border border-border text-muted hover:text-foreground hover:bg-surface transition-colors"
                    :disabled="isSubmittingRestore"
                    @click="close"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-brand bg-emerald-600 text-white hover:bg-emerald-700 shadow-xs focus:ring-2 focus:ring-emerald-500/20 transition-all disabled:opacity-50"
                    :disabled="isSubmittingRestore"
                    @click="executeRestoreUser"
                >
                    <RefreshCw v-if="isSubmittingRestore" class="h-3.5 w-3.5 animate-spin" />
                    <RotateCcw v-else class="h-3.5 w-3.5" />
                    {{ isSubmittingRestore ? 'Reactivando...' : 'Reactivar acceso' }}
                </button>
            </template>
        </BaseModal>
    </div>
</template>
