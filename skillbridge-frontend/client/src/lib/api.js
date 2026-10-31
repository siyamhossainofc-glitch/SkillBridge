/* Civic Blueprints: API requests stay explicit, typed, and ledger-friendly; no hidden persistence assumptions. */
const API_BASE = (import.meta.env.VITE_API_BASE_URL || "").replace(/\/$/, "");
export const hasApi = Boolean(API_BASE);

export async function apiRequest(path, options = {}) {
  const token = localStorage.getItem("skillbridge_token");
  const response = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers: { Accept: "application/json", "Content-Type": "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}), ...(options.headers || {}) },
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const firstError = payload.errors ? Object.values(payload.errors)[0]?.[0] : undefined;
    throw new Error(payload.message || firstError || "The request could not be completed.");
  }
  return payload;
}

export async function login(email, password) { return apiRequest("/api/login", { method: "POST", body: JSON.stringify({ email, password }) }); }
export async function register(input) { return apiRequest("/api/register", { method: "POST", body: JSON.stringify(input) }); }
export async function currentUser() { return apiRequest("/api/me"); }
export async function logout() { return apiRequest("/api/logout", { method: "POST" }); }
export async function listJobs(params = {}) { const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== undefined && value !== "").map(([key, value]) => [key, String(value)])).toString(); return apiRequest(`/api/jobs${query ? `?${query}` : ""}`); }
export async function createJob(input) { return apiRequest("/api/jobs", { method: "POST", body: JSON.stringify(input) }); }
export async function understandJob(text) { return apiRequest("/api/jobs/understand", { method: "POST", body: JSON.stringify({ text }) }); }
export async function getJob(id) { return apiRequest(`/api/jobs/${id}`); }
export async function getJobMatches(id) { return apiRequest(`/api/jobs/${id}/matches`); }
export async function getRecommendations() { return apiRequest("/api/recommendations"); }
export async function getMyJobs() { return apiRequest("/api/my-jobs"); }
export async function updateJob(id, input) { return apiRequest(`/api/jobs/${id}`, { method: "PUT", body: JSON.stringify(input) }); }
export async function cancelJob(id) { return apiRequest(`/api/jobs/${id}/cancel`, { method: "POST" }); }
export async function deleteJob(id) { return apiRequest(`/api/jobs/${id}`, { method: "DELETE" }); }
export async function listBids(jobId) { return apiRequest(`/api/jobs/${jobId}/bids`); }
export async function createBid(jobId, input) { return apiRequest(`/api/jobs/${jobId}/bids`, { method: "POST", body: JSON.stringify(input) }); }
export async function draftProposal(jobId, input = {}) { return apiRequest(`/api/jobs/${jobId}/proposal-assistance`, { method: "POST", body: JSON.stringify(input) }); }
export async function getMyBids() { return apiRequest("/api/my-bids"); }
export async function acceptBid(bidId) { return apiRequest(`/api/bids/${bidId}/accept`, { method: "POST" }); }
export async function withdrawBid(bidId) { return apiRequest(`/api/bids/${bidId}/withdraw`, { method: "POST" }); }
export async function getContracts() { return apiRequest("/api/contracts"); }
export async function getContract(id) { return apiRequest(`/api/contracts/${id}`); }
export async function startContract(id) { return apiRequest(`/api/contracts/${id}/start`, { method: "POST" }); }
export async function completeContract(id) { return apiRequest(`/api/contracts/${id}/complete`, { method: "POST" }); }
export async function cancelContract(id) { return apiRequest(`/api/contracts/${id}/cancel`, { method: "POST" }); }
export async function listReviews(contractId) { return apiRequest(`/api/contracts/${contractId}/reviews`); }
export async function createReview(contractId, input) { return apiRequest(`/api/contracts/${contractId}/review`, { method: "POST", body: JSON.stringify(input) }); }
export async function listProviderReviews(providerId) { return apiRequest(`/api/providers/${providerId}/reviews`); }
export async function listTransactions(contractId) { return apiRequest(`/api/contracts/${contractId}/transactions`); }
export async function depositContract(id) { return apiRequest(`/api/contracts/${id}/deposit`, { method: "POST" }); }
export async function releaseContract(id) { return apiRequest(`/api/contracts/${id}/release`, { method: "POST" }); }
export async function refundContract(id) { return apiRequest(`/api/contracts/${id}/refund`, { method: "POST" }); }
export async function getTransactions() { return apiRequest("/api/transactions"); }
export async function createPaymentIntent(contractId, input) { return apiRequest(`/api/contracts/${contractId}/payments`, { method: "POST", body: JSON.stringify(input) }); }
export async function getPayment(id) { return apiRequest(`/api/payments/${id}`); }
export async function getEscrow(contractId) { return apiRequest(`/api/contracts/${contractId}/escrow`); }
export async function releaseEscrow(contractId, input) { return apiRequest(`/api/contracts/${contractId}/escrow/release`, { method: "POST", body: JSON.stringify(input) }); }
export async function refundEscrow(contractId, input) { return apiRequest(`/api/contracts/${contractId}/escrow/refund`, { method: "POST", body: JSON.stringify(input) }); }
export async function getProfile() { return apiRequest("/api/profile"); }
export async function getUser(id) { return apiRequest(`/api/users/${id}`); }
export async function listProviders(params = {}) { const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== undefined && value !== "").map(([key, value]) => [key, String(value)])).toString(); return apiRequest(`/api/providers${query ? `?${query}` : ""}`); }
export async function updateProfile(input) { return apiRequest("/api/profile", { method: "PUT", body: JSON.stringify(input) }); }
export async function getProfileCompletion() { return apiRequest("/api/profile/completion"); }
export async function listSkills() { return apiRequest("/api/skills"); }
export async function listCategories() { return apiRequest("/api/categories"); }
export async function attachSkill(input) { return apiRequest("/api/profile/skills", { method: "POST", body: JSON.stringify(input) }); }
export async function detachSkill(id) { return apiRequest(`/api/profile/skills/${id}`, { method: "DELETE" }); }
export async function getConversations() { return apiRequest("/api/conversations"); }
// Laravel scopes a thread by both job post and other participant.
export async function getConversation(jobPostId, userId) { return apiRequest(`/api/jobs/${jobPostId}/messages/${userId}`); }
// Laravel requires receiver_id, job_post_id, and body for a new message.
export async function sendMessage(userId, message, jobPostId) { return apiRequest("/api/messages", { method: "POST", body: JSON.stringify({ receiver_id: userId, job_post_id: jobPostId, body: message }) }); }
export async function listServices(params = {}) { const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== undefined && value !== "").map(([key, value]) => [key, String(value)])).toString(); return apiRequest(`/api/services${query ? `?${query}` : ""}`); }
export async function getService(id) { return apiRequest(`/api/services/${id}`); }
export async function getMyServices() { return apiRequest("/api/services/mine"); }
export async function createService(input) { return apiRequest("/api/services", { method: "POST", body: JSON.stringify(input) }); }
export async function updateService(id, input) { return apiRequest(`/api/services/${id}`, { method: "PUT", body: JSON.stringify(input) }); }
export async function deleteService(id) { return apiRequest(`/api/services/${id}`, { method: "DELETE" }); }
export async function createServicePackage(serviceId, input) { return apiRequest(`/api/services/${serviceId}/packages`, { method: "POST", body: JSON.stringify(input) }); }
export async function updateServicePackage(serviceId, packageId, input) { return apiRequest(`/api/services/${serviceId}/packages/${packageId}`, { method: "PUT", body: JSON.stringify(input) }); }
export async function deleteServicePackage(serviceId, packageId) { return apiRequest(`/api/services/${serviceId}/packages/${packageId}`, { method: "DELETE" }); }
export async function getProviderPortfolio(providerId) { return apiRequest(`/api/portfolio/providers/${providerId}`); }
export async function getMyPortfolio() { return apiRequest("/api/portfolio/my"); }
export async function getPortfolioItem(id) { return apiRequest(`/api/portfolio/${id}`); }
export async function createPortfolioItem(input) { return apiRequest("/api/portfolio", { method: "POST", body: JSON.stringify(input) }); }
export async function updatePortfolioItem(id, input) { return apiRequest(`/api/portfolio/${id}`, { method: "PUT", body: JSON.stringify(input) }); }
export async function deletePortfolioItem(id) { return apiRequest(`/api/portfolio/${id}`, { method: "DELETE" }); }
export async function saveJob(id) { return apiRequest(`/api/jobs/${id}/save`, { method: "POST" }); }
export async function unsaveJob(id) { return apiRequest(`/api/jobs/${id}/save`, { method: "DELETE" }); }
export async function getSavedJobs() { return apiRequest("/api/saved-jobs"); }
export async function favoriteProvider(id) { return apiRequest(`/api/favorites/provider/${id}`, { method: "POST" }); }
export async function unfavoriteProvider(id) { return apiRequest(`/api/favorites/provider/${id}`, { method: "DELETE" }); }
export async function favoriteService(id) { return apiRequest(`/api/favorites/service/${id}`, { method: "POST" }); }
export async function unfavoriteService(id) { return apiRequest(`/api/favorites/service/${id}`, { method: "DELETE" }); }
export async function getFavorites() { return apiRequest("/api/favorites"); }
export async function getAvailability() { return apiRequest("/api/availability"); }
export async function createAvailability(input) { return apiRequest("/api/availability", { method: "POST", body: JSON.stringify(input) }); }
export async function updateAvailability(id, input) { return apiRequest(`/api/availability/${id}`, { method: "PUT", body: JSON.stringify(input) }); }
export async function deleteAvailability(id) { return apiRequest(`/api/availability/${id}`, { method: "DELETE" }); }
export async function getServiceAreas() { return apiRequest("/api/service-areas"); }
export async function createServiceArea(input) { return apiRequest("/api/service-areas", { method: "POST", body: JSON.stringify(input) }); }
export async function updateServiceArea(id, input) { return apiRequest(`/api/service-areas/${id}`, { method: "PUT", body: JSON.stringify(input) }); }
export async function deleteServiceArea(id) { return apiRequest(`/api/service-areas/${id}`, { method: "DELETE" }); }
export async function getNotifications(page = 1, perPage = 15) { return apiRequest(`/api/notifications?page=${page}&per_page=${perPage}`); }
export async function getUnreadCount() { return apiRequest('/api/notifications/unread-count'); }
export async function markNotificationRead(id) { return apiRequest(`/api/notifications/${id}/read`, { method: 'PATCH' }); }
export async function markAllNotificationsRead() { return apiRequest('/api/notifications/read-all', { method: 'PATCH' }); }

// --- In-app Conversations (Task 6B) ---
export async function listConversations(params = {}) { const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== undefined && value !== "").map(([key, value]) => [key, String(value)])).toString(); return apiRequest(`/api/conversations${query ? `?${query}` : ""}`); }
export async function listMessagingUsers(params = {}) { const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== undefined && value !== "").map(([key, value]) => [key, String(value)])).toString(); return apiRequest(`/api/messaging-users${query ? `?${query}` : ""}`); }
export async function startConversation(userId) { return apiRequest('/api/conversations', { method: 'POST', body: JSON.stringify({ user_id: userId }) }); }
export async function getConversationMessages(conversationId, page = 1, perPage = 30) { return apiRequest(`/api/conversations/${conversationId}/messages?page=${page}&per_page=${perPage}`); }
export async function sendConversationMessage(conversationId, body) { return apiRequest(`/api/conversations/${conversationId}/messages`, { method: 'POST', body: JSON.stringify({ body }) }); }
export async function markMessageRead(messageId) { return apiRequest(`/api/messages/${messageId}/read`, { method: 'PATCH' }); }

export async function getVerification() { return apiRequest("/api/verification"); }
export async function submitVerification() { return apiRequest("/api/verification", { method: "POST" }); }
export async function listAdminVerifications(params = {}) { const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== undefined && value !== "").map(([key, value]) => [key, String(value)])).toString(); return apiRequest(`/api/admin/verifications${query ? `?${query}` : ""}`); }
export async function getAdminVerification(id) { return apiRequest(`/api/admin/verifications/${id}`); }
export async function approveVerification(id) { return apiRequest(`/api/admin/verifications/${id}/approve`, { method: "POST" }); }
export async function rejectVerification(id, rejection_reason) { return apiRequest(`/api/admin/verifications/${id}/reject`, { method: "POST", body: JSON.stringify({ rejection_reason }) }); }

export async function listMilestones(contractId) { return apiRequest(`/api/contracts/${contractId}/milestones`); }
export async function createMilestone(contractId, input) { return apiRequest(`/api/contracts/${contractId}/milestones`, { method: "POST", body: JSON.stringify(input) }); }
export async function updateMilestone(id, input) { return apiRequest(`/api/milestones/${id}`, { method: "PATCH", body: JSON.stringify(input) }); }
export async function deleteMilestone(id) { return apiRequest(`/api/milestones/${id}`, { method: "DELETE" }); }
export async function startMilestone(id) { return apiRequest(`/api/milestones/${id}/start`, { method: "POST" }); }
export async function submitMilestone(id) { return apiRequest(`/api/milestones/${id}/submit`, { method: "POST" }); }
export async function approveMilestone(id) { return apiRequest(`/api/milestones/${id}/approve`, { method: "POST" }); }
export async function rejectMilestone(id) { return apiRequest(`/api/milestones/${id}/reject`, { method: "POST" }); }

export async function listContractDisputes(contractId) { return apiRequest(`/api/contracts/${contractId}/disputes`); }
export async function createContractDispute(contractId, input) { return apiRequest(`/api/contracts/${contractId}/disputes`, { method: "POST", body: JSON.stringify(input) }); }
export async function getDispute(id) { return apiRequest(`/api/disputes/${id}`); }
export async function cancelDispute(id) { return apiRequest(`/api/disputes/${id}/cancel`, { method: "POST" }); }
export async function listAdminDisputes(params = {}) { const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== undefined && value !== "").map(([key, value]) => [key, String(value)])).toString(); return apiRequest(`/api/admin/disputes${query ? `?${query}` : ""}`); }
export async function getAdminDispute(id) { return apiRequest(`/api/admin/disputes/${id}`); }
export async function resolveDispute(id, resolution) { return apiRequest(`/api/admin/disputes/${id}/resolve`, { method: "POST", body: JSON.stringify({ resolution }) }); }
export async function rejectDispute(id, resolution) { return apiRequest(`/api/admin/disputes/${id}/reject`, { method: "POST", body: JSON.stringify({ resolution }) }); }

export async function listAdminUsers(params = {}) { const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== undefined && value !== '').map(([key, value]) => [key, String(value)])).toString(); return apiRequest(`/api/admin/users${query ? `?${query}` : ''}`); }
export async function getAdminUser(id) { return apiRequest(`/api/admin/users/${id}`); }
export async function suspendAdminUser(id, reason) { return apiRequest(`/api/admin/users/${id}/suspend`, { method: 'PATCH', body: JSON.stringify({ reason }) }); }
export async function restoreAdminUser(id) { return apiRequest(`/api/admin/users/${id}/restore`, { method: 'PATCH' }); }
export async function getAdminModerationSummary() { return apiRequest('/api/admin/summary'); }
export async function getMarketplaceIntelligence(days = 30) { return apiRequest(`/api/admin/intelligence?days=${encodeURIComponent(days)}`); }
export async function listAdminContent(target, params = {}) { const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== undefined && value !== '').map(([key, value]) => [key, String(value)])).toString(); return apiRequest(`/api/admin/${target}${query ? `?${query}` : ''}`); }
export async function hideAdminContent(target, id, reason) { return apiRequest(`/api/admin/${target}/${id}/hide`, { method: 'PATCH', body: JSON.stringify({ reason }) }); }
export async function restoreAdminContent(target, id) { return apiRequest(`/api/admin/${target}/${id}/restore`, { method: 'PATCH' }); }
export async function listAdminAuditLogs(params = {}) { const query = new URLSearchParams(Object.entries(params).filter(([, value]) => value !== undefined && value !== '').map(([key, value]) => [key, String(value)])).toString(); return apiRequest(`/api/admin/audit-logs${query ? `?${query}` : ''}`); }
