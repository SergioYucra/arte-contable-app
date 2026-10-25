<script setup>
import { ref, computed, onMounted } from 'vue';
import Fuse from 'fuse.js';
import {
    Search,
    Shield,
    Users,
    Filter,
    Check,
    X,
    UserCheck,
    RefreshCw,
    AlertCircle,
    User as UserIcon,
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
});

// Estado reactivo
const users = ref([...props.initialUsers]);
const searchQuery = ref('');
const selectedRoleFilter = ref('ALL');

// Modal de cambio de rol
const isModalOpen = ref(false);
const activeUser = ref(null);
const targetRole = ref('');
const isSubmitting = ref(false);

// Notificaciones
const notification = ref(null);
const modalError = ref(null);

// Inicializar Fuse.js para búsqueda difusa en memoria (0% estrés al servidor)
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

// Lista filtrada reactiva (Búsqueda Fuse.js + Filtro por Rol)
const filteredUsers = computed(() => {
    let result = users.value;

    // 1. Filtrar por rol si no es ALL
    if (selectedRoleFilter.value !== 'ALL') {
        result = result.filter((u) => u.role === selectedRoleFilter.value);
    }

    // 2. Si hay texto en el buscador, aplicar Fuse.js en memoria
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

// Conteo por roles
const roleCounts = computed(() => {
    const counts = { ALL: users.value.length };
    props.availableRoles.forEach((role) => {
        counts[role] = users.value.filter((u) => u.role === role).length;
    });
    return counts;
});

// Colores de insignias según rol
function getRoleBadgeClass(role) {
    switch (role) {
        case 'SuperAdmin':
            return 'bg-purple-500/10 text-purple-700 border-purple-500/20';
        case 'Admin':
            return 'bg-primary/10 text-primary border-primary/20';
        case 'Manager':
            return 'bg-sky-500/10 text-sky-700 border-sky-500/20';
        default:
            return 'bg-slate-500/10 text-slate-700 border-slate-500/20';
    }
}

// Abrir modal de asignación de rol
function openRoleModal(user) {
    activeUser.value = user;
    targetRole.value = user.role;
    modalError.value = null;
    isModalOpen.value = true;
}

// Enviar cambio de rol vía Axios
async function saveRoleChange() {
    if (!activeUser.value || targetRole.value === activeUser.value.role) {
        isModalOpen.value = false;
        return;
    }

    isSubmitting.value = true;
    modalError.value = null;

    const url = props.updateRoleUrlTemplate.replace(':id', activeUser.value.id);

    try {
        const response = await window.axios.patch(url, {
            role: targetRole.value,
        });

        // Actualizar el estado local reactivo inmediatamente
        const userIndex = users.value.findIndex((u) => u.id === activeUser.value.id);
        if (userIndex !== -1) {
            users.value[userIndex].role = targetRole.value;
        }

        // Re-indexar Fuse con los nuevos datos
        setupFuse();

        notification.value = {
            type: 'success',
            title: 'Rol actualizado',
            message: response.data.message || `El rol fue actualizado a ${targetRole.value}.`,
        };

        isModalOpen.value = false;
    } catch (error) {
        modalError.value = error.response?.data?.message || 'Ocurrió un error al intentar cambiar el rol.';
    } finally {
        isSubmitting.value = false;
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

        <!-- Barra de herramientas: Búsqueda instantánea con Fuse.js y Filtros -->
        <div class="bg-surface border border-border rounded-brand p-4 shadow-xs flex flex-col md:flex-row gap-4 items-center justify-between">
            <!-- Buscador en tiempo real -->
            <div class="relative w-full md:w-96">
                <Search class="absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted pointer-events-none" />
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Buscar por nombre, email o rol..."
                    class="w-full pl-10 pr-9 py-2 bg-background border border-border rounded-brand text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                />
                <button
                    v-if="searchQuery"
                    type="button"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-muted hover:text-foreground"
                    @click="searchQuery = ''"
                    title="Limpiar búsqueda"
                >
                    <X class="h-4 w-4" />
                </button>
            </div>

            <!-- Filtros por Rol tipo Pills -->
            <div class="flex items-center gap-1.5 overflow-x-auto w-full md:w-auto pb-1 md:pb-0">
                <button
                    type="button"
                    class="px-3 py-1.5 rounded-full text-xs font-medium transition-all"
                    :class="selectedRoleFilter === 'ALL'
                        ? 'bg-primary text-primary-foreground shadow-xs'
                        : 'bg-background text-muted hover:text-foreground border border-border'"
                    @click="selectedRoleFilter = 'ALL'"
                >
                    Todos ({{ roleCounts.ALL }})
                </button>

                <button
                    v-for="role in availableRoles"
                    :key="role"
                    type="button"
                    class="px-3 py-1.5 rounded-full text-xs font-medium transition-all"
                    :class="selectedRoleFilter === role
                        ? 'bg-primary text-primary-foreground shadow-xs'
                        : 'bg-background text-muted hover:text-foreground border border-border'"
                    @click="selectedRoleFilter = role"
                >
                    {{ role }} ({{ roleCounts[role] || 0 }})
                </button>
            </div>
        </div>

        <!-- Indicador de estado de Fuse.js (0% estrés al servidor) -->
        <div class="flex items-center justify-between text-xs text-muted px-1">
            <span class="flex items-center gap-1.5">
                <Users class="h-3.5 w-3.5" />
                Mostrando <strong>{{ filteredUsers.length }}</strong> de {{ users.length }} usuarios registrados
            </span>
            <span class="hidden sm:inline-flex items-center gap-1 text-[11px] font-medium text-emerald-600">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                Filtro instantáneo activo en cliente
            </span>
        </div>

        <!-- Tabla interactiva -->
        <div class="bg-surface border border-border rounded-brand shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-background border-b border-border text-xs uppercase font-semibold text-muted tracking-wider">
                        <tr>
                            <th class="px-6 py-3.5">Usuario</th>
                            <th class="px-6 py-3.5">Rol de Seguridad</th>
                            <th class="px-6 py-3.5 hidden md:table-cell">Registrado</th>
                            <th class="px-6 py-3.5 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr
                            v-for="user in filteredUsers"
                            :key="user.id"
                            class="hover:bg-background/60 transition-colors"
                        >
                            <!-- Columna Usuario -->
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="h-9 w-9 rounded-full bg-primary/10 text-primary flex items-center justify-center font-semibold text-sm border border-primary/20">
                                        {{ user.name.charAt(0).toUpperCase() }}
                                    </div>
                                    <div>
                                        <div class="font-medium text-foreground">{{ user.name }}</div>
                                        <div class="text-xs text-muted">{{ user.email }}</div>
                                    </div>
                                </div>
                            </td>

                            <!-- Columna Rol Actual -->
                            <td class="px-6 py-4">
                                <span
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border"
                                    :class="getRoleBadgeClass(user.role)"
                                >
                                    <Shield class="h-3 w-3" />
                                    {{ user.role }}
                                </span>
                            </td>

                            <!-- Columna Fecha -->
                            <td class="px-6 py-4 text-xs text-muted hidden md:table-cell">
                                {{ user.created_at }}
                            </td>

                            <!-- Columna Acciones -->
                            <td class="px-6 py-4 text-right">
                                <button
                                    v-if="user.can_edit !== false"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-brand text-xs font-medium bg-surface border border-border text-foreground hover:bg-background hover:border-primary/40 focus:ring-2 focus:ring-primary/20 transition-all"
                                    @click="openRoleModal(user)"
                                >
                                    <Shield class="h-3.5 w-3.5 text-primary" />
                                    Cambiar Rol
                                </button>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-brand text-xs text-muted bg-background border border-border/80 cursor-not-allowed"
                                    title="Las cuentas de SuperAdmin solo pueden ser modificadas por otro SuperAdmin"
                                >
                                    <Shield class="h-3 w-3 text-muted/60" />
                                    Protegido
                                </span>
                            </td>
                        </tr>

                        <!-- Estado vacío si la búsqueda no arroja resultados -->
                        <tr v-if="filteredUsers.length === 0">
                            <td colspan="4" class="px-6 py-12 text-center">
                                <div class="max-w-xs mx-auto space-y-3">
                                    <div class="h-10 w-10 mx-auto rounded-full bg-muted/10 text-muted flex items-center justify-center">
                                        <Search class="h-5 w-5" />
                                    </div>
                                    <h4 class="text-sm font-semibold text-foreground">No se encontraron usuarios</h4>
                                    <p class="text-xs text-muted">
                                        No hay coincidencias para "{{ searchQuery }}" con el filtro seleccionado.
                                    </p>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-brand text-xs font-medium bg-primary text-primary-foreground hover:bg-primary-hover transition-colors"
                                        @click="searchQuery = ''; selectedRoleFilter = 'ALL'"
                                    >
                                        Limpiar filtros
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal interactivo para cambiar rol (utiliza BaseModal de Fase 5) -->
        <BaseModal
            v-model="isModalOpen"
            title="Modificar Rol de Usuario"
            max-width="md"
        >
            <div v-if="activeUser" class="space-y-4">
                <p class="text-xs text-muted">
                    Selecciona el nuevo nivel de acceso para <strong>{{ activeUser.name }}</strong> ({{ activeUser.email }}):
                </p>

                <!-- Alerta de error si el servidor rechaza la operación -->
                <BaseAlert v-if="modalError" type="error" title="No se pudo cambiar el rol">
                    {{ modalError }}
                </BaseAlert>

                <!-- Opciones de roles disponibles -->
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
                                <span class="block text-sm font-semibold text-foreground">{{ role }}</span>
                                <span class="block text-xs text-muted">
                                    {{ role === 'SuperAdmin' ? 'Acceso irrestricto a todo el sistema' : '' }}
                                    {{ role === 'Admin' ? 'Gestión de usuarios y auditoría' : '' }}
                                    {{ role === 'Manager' ? 'Acceso a reportes y dashboard' : '' }}
                                    {{ role === 'User' ? 'Acceso estándar sin permisos de gestión' : '' }}
                                </span>
                            </div>
                        </div>

                        <span
                            class="px-2 py-0.5 rounded-full text-[10px] font-semibold border"
                            :class="getRoleBadgeClass(role)"
                        >
                            {{ role }}
                        </span>
                    </label>
                </div>
            </div>

            <template #footer="{ close }">
                <button
                    type="button"
                    class="px-4 py-2 text-xs font-medium rounded-brand border border-border text-muted hover:text-foreground hover:bg-surface transition-colors"
                    :disabled="isSubmitting"
                    @click="close"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-brand bg-primary text-primary-foreground hover:bg-primary-hover shadow-xs focus:ring-2 focus:ring-primary/20 transition-all disabled:opacity-50"
                    :disabled="isSubmitting || targetRole === activeUser?.role"
                    @click="saveRoleChange"
                >
                    <RefreshCw v-if="isSubmitting" class="h-3.5 w-3.5 animate-spin" />
                    <Check v-else class="h-3.5 w-3.5" />
                    {{ isSubmitting ? 'Guardando...' : 'Confirmar nuevo rol' }}
                </button>
            </template>
        </BaseModal>
    </div>
</template>
