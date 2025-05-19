import { Chart } from "@/components/ui/chart"
/**
 * EliteFit Gym - Trainer Dashboard JavaScript
 * This file contains all the JavaScript functionality for the trainer dashboard
 */

document.addEventListener("DOMContentLoaded", () => {
  // Initialize all dashboard components
  initializeSidebar()
  initializeCharts()
  initializeModals()
  initializeFormValidation()
  initializeDataTables()
  initializeNotifications()
  initializeDatePickers()
  initializeSearchFilters()
  setupEventListeners()

  // Check for URL parameters to trigger specific actions
  handleUrlParameters()
})

/**
 * Sidebar functionality
 */
function initializeSidebar() {
  // Mobile menu toggle
  const mobileMenuToggle = document.getElementById("mobileMenuToggle")
  const sidebar = document.getElementById("sidebar")

  if (mobileMenuToggle && sidebar) {
    mobileMenuToggle.addEventListener("click", () => {
      sidebar.classList.toggle("show")
    })

    // Close sidebar when clicking outside on mobile
    document.addEventListener("click", (event) => {
      if (
        window.innerWidth < 768 &&
        sidebar.classList.contains("show") &&
        !sidebar.contains(event.target) &&
        event.target !== mobileMenuToggle
      ) {
        sidebar.classList.remove("show")
      }
    })
  }

  // Add active class to current page in sidebar
  const currentPath = window.location.pathname
  const filename = currentPath.substring(currentPath.lastIndexOf("/") + 1)

  document.querySelectorAll(".sidebar-menu a").forEach((link) => {
    if (link.getAttribute("href") === filename) {
      link.classList.add("active")
    } else {
      link.classList.remove("active")
    }
  })

  // Handle sidebar collapse for smaller screens
  window.addEventListener("resize", () => {
    if (window.innerWidth >= 768 && sidebar) {
      sidebar.classList.remove("show")
    }
  })
}

/**
 * Charts and data visualization
 */
function initializeCharts() {
  // Check if Chart.js is available
  if (typeof Chart === "undefined") return

  // Progress charts (weight, body fat, muscle mass)
  initializeProgressCharts()

  // Workout activity chart
  initializeWorkoutActivityChart()

  // Member statistics chart
  initializeMemberStatsChart()
}

function initializeProgressCharts() {
  // Weight chart
  const weightChartEl = document.getElementById("weightChart")
  if (weightChartEl) {
    const ctx = weightChartEl.getContext("2d")
    const chartData = getChartData("weight")

    new Chart(ctx, {
      type: "line",
      data: {
        labels: chartData.labels,
        datasets: [
          {
            label: "Weight (kg)",
            data: chartData.values,
            backgroundColor: "rgba(255, 136, 0, 0.2)",
            borderColor: "rgba(255, 136, 0, 1)",
            borderWidth: 2,
            tension: 0.1,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: false,
            ticks: {
              callback: (value) => value + " kg",
            },
          },
        },
        plugins: {
          tooltip: {
            callbacks: {
              label: (context) => context.dataset.label + ": " + context.raw + " kg",
            },
          },
        },
      },
    })
  }

  // Body fat chart
  const bodyFatChartEl = document.getElementById("bodyFatChart")
  if (bodyFatChartEl) {
    const ctx = bodyFatChartEl.getContext("2d")
    const chartData = getChartData("bodyFat")

    new Chart(ctx, {
      type: "line",
      data: {
        labels: chartData.labels,
        datasets: [
          {
            label: "Body Fat (%)",
            data: chartData.values,
            backgroundColor: "rgba(220, 53, 69, 0.2)",
            borderColor: "rgba(220, 53, 69, 1)",
            borderWidth: 2,
            tension: 0.1,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: false,
            ticks: {
              callback: (value) => value + "%",
            },
          },
        },
        plugins: {
          tooltip: {
            callbacks: {
              label: (context) => context.dataset.label + ": " + context.raw + "%",
            },
          },
        },
      },
    })
  }

  // Muscle mass chart
  const muscleMassChartEl = document.getElementById("muscleMassChart")
  if (muscleMassChartEl) {
    const ctx = muscleMassChartEl.getContext("2d")
    const chartData = getChartData("muscleMass")

    new Chart(ctx, {
      type: "line",
      data: {
        labels: chartData.labels,
        datasets: [
          {
            label: "Muscle Mass (kg)",
            data: chartData.values,
            backgroundColor: "rgba(40, 167, 69, 0.2)",
            borderColor: "rgba(40, 167, 69, 1)",
            borderWidth: 2,
            tension: 0.1,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: false,
            ticks: {
              callback: (value) => value + " kg",
            },
          },
        },
        plugins: {
          tooltip: {
            callbacks: {
              label: (context) => context.dataset.label + ": " + context.raw + " kg",
            },
          },
        },
      },
    })
  }
}

function initializeWorkoutActivityChart() {
  const workoutActivityChartEl = document.getElementById("workoutActivityChart")
  if (workoutActivityChartEl) {
    const ctx = workoutActivityChartEl.getContext("2d")

    // Get the last 7 days for labels
    const labels = []
    for (let i = 6; i >= 0; i--) {
      const date = new Date()
      date.setDate(date.getDate() - i)
      labels.push(date.toLocaleDateString("en-US", { weekday: "short" }))
    }

    // Sample data - in a real app, this would come from the server
    const data = [3, 5, 2, 6, 4, 7, 3]

    new Chart(ctx, {
      type: "bar",
      data: {
        labels: labels,
        datasets: [
          {
            label: "Workout Sessions",
            data: data,
            backgroundColor: "rgba(255, 136, 0, 0.7)",
            borderColor: "rgba(255, 136, 0, 1)",
            borderWidth: 1,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              stepSize: 1,
            },
          },
        },
      },
    })
  }
}

function initializeMemberStatsChart() {
  const memberStatsChartEl = document.getElementById("memberStatsChart")
  if (memberStatsChartEl) {
    const ctx = memberStatsChartEl.getContext("2d")

    // Sample data - in a real app, this would come from the server
    new Chart(ctx, {
      type: "doughnut",
      data: {
        labels: ["Active", "Inactive", "New"],
        datasets: [
          {
            data: [70, 15, 15],
            backgroundColor: ["rgba(40, 167, 69, 0.7)", "rgba(108, 117, 125, 0.7)", "rgba(255, 136, 0, 0.7)"],
            borderColor: ["rgba(40, 167, 69, 1)", "rgba(108, 117, 125, 1)", "rgba(255, 136, 0, 1)"],
            borderWidth: 1,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: "bottom",
          },
        },
      },
    })
  }
}

// Helper function to get chart data from data attributes or default values
function getChartData(type) {
  // Try to get data from hidden input fields or data attributes
  const dataElement = document.getElementById(`${type}ChartData`)

  if (dataElement) {
    try {
      const data = JSON.parse(dataElement.value || dataElement.getAttribute("data-values"))
      return {
        labels: data.labels || [],
        values: data.values || [],
      }
    } catch (e) {
      console.error("Error parsing chart data:", e)
    }
  }

  // Return sample data if no data is available
  return {
    labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun"],
    values: [65, 64, 63, 62, 60, 61],
  }
}

/**
 * Modal handling
 */
function initializeModals() {
  // Get all modals
  const modals = document.querySelectorAll(".modal")

  // Get all elements that open a modal
  const modalTriggers = document.querySelectorAll("[data-modal]")

  // Get all close buttons
  const closeButtons = document.querySelectorAll(".close-modal")

  // Add click event to all modal triggers
  modalTriggers.forEach((trigger) => {
    trigger.addEventListener("click", function (e) {
      e.preventDefault()
      const modalId = this.getAttribute("data-modal")
      const modal = document.getElementById(modalId)
      if (modal) {
        openModal(modal)
      }
    })
  })

  // Add click event to all close buttons
  closeButtons.forEach((button) => {
    button.addEventListener("click", function () {
      const modal = this.closest(".modal")
      closeModal(modal)
    })
  })

  // Close modal when clicking outside
  modals.forEach((modal) => {
    modal.addEventListener("click", function (e) {
      if (e.target === this) {
        closeModal(this)
      }
    })
  })

  // Close modal with Escape key
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      modals.forEach((modal) => {
        if (modal.style.display === "flex") {
          closeModal(modal)
        }
      })
    }
  })
}

// Function to open a modal
function openModal(modal) {
  modal.style.display = "flex"
  document.body.classList.add("modal-open")

  // Trigger an event that can be listened to
  const event = new CustomEvent("modalOpened", { detail: { modalId: modal.id } })
  document.dispatchEvent(event)
}

// Function to close a modal
function closeModal(modal) {
  modal.style.display = "none"
  document.body.classList.remove("modal-open")

  // Trigger an event that can be listened to
  const event = new CustomEvent("modalClosed", { detail: { modalId: modal.id } })
  document.dispatchEvent(event)
}

/**
 * Form validation
 */
function initializeFormValidation() {
  // Get all forms that need validation
  const forms = document.querySelectorAll("form[data-validate]")

  forms.forEach((form) => {
    form.addEventListener("submit", function (e) {
      if (!validateForm(this)) {
        e.preventDefault()
        e.stopPropagation()
      }
    })

    // Real-time validation on input
    const inputs = form.querySelectorAll("input, select, textarea")
    inputs.forEach((input) => {
      input.addEventListener("blur", function () {
        validateInput(this)
      })

      // Special handling for password fields
      if (input.type === "password" && input.id === "new_password") {
        input.addEventListener("input", function () {
          updatePasswordStrength(this)
        })
      }

      // Special handling for password confirmation
      if (input.id === "confirm_password") {
        input.addEventListener("input", function () {
          validatePasswordConfirmation(this)
        })
      }
    })
  })

  // Initialize password strength meter
  initializePasswordStrengthMeter()
}

// Validate an entire form
function validateForm(form) {
  let isValid = true

  // Validate all inputs
  const inputs = form.querySelectorAll("input, select, textarea")
  inputs.forEach((input) => {
    if (!validateInput(input)) {
      isValid = false
    }
  })

  // Check for password confirmation if applicable
  const newPassword = form.querySelector("#new_password")
  const confirmPassword = form.querySelector("#confirm_password")

  if (newPassword && confirmPassword) {
    if (newPassword.value !== confirmPassword.value) {
      setInputError(confirmPassword, "Passwords do not match")
      isValid = false
    }
  }

  return isValid
}

// Validate a single input
function validateInput(input) {
  // Skip disabled or readonly inputs
  if (input.disabled || input.readOnly) {
    return true
  }

  // Skip inputs without validation rules
  if (!input.required && !input.pattern && !input.minLength && !input.maxLength && !input.min && !input.max) {
    return true
  }

  // Check if input is required and empty
  if (input.required && !input.value.trim()) {
    setInputError(input, "This field is required")
    return false
  }

  // Check pattern
  if (input.pattern && input.value) {
    const pattern = new RegExp(input.pattern)
    if (!pattern.test(input.value)) {
      setInputError(input, input.dataset.errorPattern || "Please match the requested format")
      return false
    }
  }

  // Check min/max length
  if (input.minLength && input.value.length < input.minLength) {
    setInputError(input, `Please enter at least ${input.minLength} characters`)
    return false
  }

  if (input.maxLength && input.value.length > input.maxLength) {
    setInputError(input, `Please enter no more than ${input.maxLength} characters`)
    return false
  }

  // Check min/max values for number inputs
  if (input.type === "number") {
    const value = Number.parseFloat(input.value)

    if (!isNaN(value)) {
      if (input.min !== "" && value < Number.parseFloat(input.min)) {
        setInputError(input, `Value must be greater than or equal to ${input.min}`)
        return false
      }

      if (input.max !== "" && value > Number.parseFloat(input.max)) {
        setInputError(input, `Value must be less than or equal to ${input.max}`)
        return false
      }
    }
  }

  // Check email format
  if (input.type === "email" && input.value) {
    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
    if (!emailPattern.test(input.value)) {
      setInputError(input, "Please enter a valid email address")
      return false
    }
  }

  // If we got here, the input is valid
  clearInputError(input)
  return true
}

// Set error message for an input
function setInputError(input, message) {
  // Clear any existing error
  clearInputError(input)

  // Add error class to input
  input.classList.add("is-invalid")

  // Create error message element
  const errorElement = document.createElement("div")
  errorElement.className = "invalid-feedback"
  errorElement.textContent = message

  // Add error message after input
  input.parentNode.appendChild(errorElement)
}

// Clear error message for an input
function clearInputError(input) {
  input.classList.remove("is-invalid")

  // Remove any existing error messages
  const errorElement = input.parentNode.querySelector(".invalid-feedback")
  if (errorElement) {
    errorElement.remove()
  }
}

// Initialize password strength meter
function initializePasswordStrengthMeter() {
  const passwordInput = document.getElementById("new_password")
  const passwordStrengthMeter = document.getElementById("passwordStrengthMeter")
  const passwordStrengthText = document.getElementById("passwordStrengthText")

  if (passwordInput && passwordStrengthMeter && passwordStrengthText) {
    passwordInput.addEventListener("input", function () {
      updatePasswordStrength(this)
    })
  }
}

// Update password strength meter
function updatePasswordStrength(input) {
  const passwordStrengthMeter = document.getElementById("passwordStrengthMeter")
  const passwordStrengthText = document.getElementById("passwordStrengthText")

  if (!passwordStrengthMeter || !passwordStrengthText) return

  const password = input.value
  let strength = 0
  let feedback = "Too weak"

  // Calculate password strength
  if (password.length >= 8) strength += 1
  if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength += 1
  if (password.match(/\d/)) strength += 1
  if (password.match(/[^a-zA-Z\d]/)) strength += 1

  // Update UI based on strength
  switch (strength) {
    case 0:
      passwordStrengthMeter.style.width = "0%"
      passwordStrengthMeter.style.backgroundColor = "#dc3545"
      feedback = "Too weak"
      break
    case 1:
      passwordStrengthMeter.style.width = "25%"
      passwordStrengthMeter.style.backgroundColor = "#dc3545"
      feedback = "Weak"
      break
    case 2:
      passwordStrengthMeter.style.width = "50%"
      passwordStrengthMeter.style.backgroundColor = "#ffc107"
      feedback = "Fair"
      break
    case 3:
      passwordStrengthMeter.style.width = "75%"
      passwordStrengthMeter.style.backgroundColor = "#28a745"
      feedback = "Good"
      break
    case 4:
      passwordStrengthMeter.style.width = "100%"
      passwordStrengthMeter.style.backgroundColor = "#28a745"
      feedback = "Strong"
      break
  }

  passwordStrengthText.textContent = `Password strength: ${feedback}`
}

// Validate password confirmation
function validatePasswordConfirmation(input) {
  const newPassword = document.getElementById("new_password")

  if (newPassword && input.value !== newPassword.value) {
    setInputError(input, "Passwords do not match")
    return false
  }

  clearInputError(input)
  return true
}

/**
 * Data tables functionality
 */
function initializeDataTables() {
  // Add sorting, pagination, and search to tables
  const tables = document.querySelectorAll("table[data-sortable]")

  tables.forEach((table) => {
    // Add sorting functionality
    const headers = table.querySelectorAll("th[data-sortable]")

    headers.forEach((header) => {
      header.addEventListener("click", function () {
        const column = this.cellIndex
        const sortDirection = this.getAttribute("data-sort-direction") === "asc" ? "desc" : "asc"

        // Update sort direction
        headers.forEach((h) => h.removeAttribute("data-sort-direction"))
        this.setAttribute("data-sort-direction", sortDirection)

        // Sort the table
        sortTable(table, column, sortDirection)
      })

      // Add sort indicator
      header.style.cursor = "pointer"
      header.innerHTML += ' <span class="sort-indicator"></span>'
    })
  })

  // Initialize search functionality
  const searchInputs = document.querySelectorAll("input[data-search-table]")

  searchInputs.forEach((input) => {
    input.addEventListener("keyup", function () {
      const tableId = this.getAttribute("data-search-table")
      const table = document.getElementById(tableId)

      if (table) {
        searchTable(table, this.value)
      }
    })
  })
}

// Sort a table by a column
function sortTable(table, column, direction) {
  const tbody = table.querySelector("tbody")
  const rows = Array.from(tbody.querySelectorAll("tr"))

  // Sort the rows
  rows.sort((a, b) => {
    const aValue = a.cells[column].textContent.trim()
    const bValue = b.cells[column].textContent.trim()

    // Check if values are dates
    const aDate = new Date(aValue)
    const bDate = new Date(bValue)

    if (!isNaN(aDate) && !isNaN(bDate)) {
      return direction === "asc" ? aDate - bDate : bDate - aDate
    }

    // Check if values are numbers
    const aNum = Number.parseFloat(aValue)
    const bNum = Number.parseFloat(bValue)

    if (!isNaN(aNum) && !isNaN(bNum)) {
      return direction === "asc" ? aNum - bNum : bNum - aNum
    }

    // Default to string comparison
    return direction === "asc" ? aValue.localeCompare(bValue) : bValue.localeCompare(aValue)
  })

  // Re-append rows in the new order
  rows.forEach((row) => tbody.appendChild(row))

  // Update sort indicators
  const headers = table.querySelectorAll("th")
  headers.forEach((header) => {
    const indicator = header.querySelector(".sort-indicator")
    if (indicator) {
      indicator.textContent = ""
    }
  })

  // Add indicator to the sorted column
  const sortedHeader = table.querySelector(`th[data-sort-direction="${direction}"]`)
  if (sortedHeader) {
    const indicator = sortedHeader.querySelector(".sort-indicator")
    if (indicator) {
      indicator.textContent = direction === "asc" ? " ↑" : " ↓"
    }
  }
}

// Search a table
function searchTable(table, query) {
  const rows = table.querySelectorAll("tbody tr")
  const lowerQuery = query.toLowerCase()

  rows.forEach((row) => {
    const text = row.textContent.toLowerCase()
    row.style.display = text.includes(lowerQuery) ? "" : "none"
  })

  // Show a message if no results
  let noResultsMessage = table.nextElementSibling

  if (!noResultsMessage || !noResultsMessage.classList.contains("no-results-message")) {
    noResultsMessage = document.createElement("div")
    noResultsMessage.className = "no-results-message"
    noResultsMessage.textContent = "No matching results found"
    table.parentNode.insertBefore(noResultsMessage, table.nextSibling)
  }

  // Check if any rows are visible
  let hasVisibleRows = false
  rows.forEach((row) => {
    if (row.style.display !== "none") {
      hasVisibleRows = true
    }
  })

  noResultsMessage.style.display = hasVisibleRows ? "none" : "block"
}

/**
 * Notifications
 */
function initializeNotifications() {
  // Auto-hide alerts after a delay
  const alerts = document.querySelectorAll(".alert:not([data-persistent])")

  alerts.forEach((alert) => {
    setTimeout(() => {
      fadeOut(alert, 500, () => {
        alert.remove()
      })
    }, 5000)

    // Add close button functionality
    const closeBtn = alert.querySelector(".close")
    if (closeBtn) {
      closeBtn.addEventListener("click", () => {
        fadeOut(alert, 300, () => {
          alert.remove()
        })
      })
    }
  })
}

// Fade out an element
function fadeOut(element, duration, callback) {
  element.style.transition = `opacity ${duration}ms ease`
  element.style.opacity = "0"

  setTimeout(() => {
    if (callback) callback()
  }, duration)
}

// Show a notification
function showNotification(message, type = "success", duration = 5000) {
  const notification = document.createElement("div")
  notification.className = `alert alert-${type}`
  notification.innerHTML = `
        <i class="fas fa-${type === "success" ? "check-circle" : type === "danger" ? "exclamation-circle" : "info-circle"}"></i>
        <div>${message}</div>
        <button type="button" class="close">&times;</button>
    `

  // Add to notifications container or create one
  let container = document.querySelector(".notifications-container")
  if (!container) {
    container = document.createElement("div")
    container.className = "notifications-container"
    document.body.appendChild(container)
  }

  container.appendChild(notification)

  // Auto-hide after duration
  if (duration > 0) {
    setTimeout(() => {
      fadeOut(notification, 500, () => {
        notification.remove()

        // Remove container if empty
        if (container.children.length === 0) {
          container.remove()
        }
      })
    }, duration)
  }

  // Add close button functionality
  const closeBtn = notification.querySelector(".close")
  if (closeBtn) {
    closeBtn.addEventListener("click", () => {
      fadeOut(notification, 300, () => {
        notification.remove()

        // Remove container if empty
        if (container.children.length === 0) {
          container.remove()
        }
      })
    })
  }

  return notification
}

/**
 * Date pickers
 */
function initializeDatePickers() {
  // Add date picker functionality to date inputs
  const dateInputs = document.querySelectorAll('input[type="date"]')

  dateInputs.forEach((input) => {
    // Set default value to today if not set and has data-default-today attribute
    if (input.hasAttribute("data-default-today") && !input.value) {
      input.value = new Date().toISOString().split("T")[0]
    }

    // Add min/max date validation
    input.addEventListener("change", function () {
      validateDateRange(this)
    })
  })

  // Initialize date range pickers
  initializeDateRangePickers()
}

// Validate date range
function validateDateRange(input) {
  const value = input.value
  if (!value) return

  const date = new Date(value)

  // Check min date
  if (input.min) {
    const minDate = new Date(input.min)
    if (date < minDate) {
      input.value = input.min
      showNotification(`Date adjusted to minimum allowed: ${formatDate(minDate)}`, "warning")
    }
  }

  // Check max date
  if (input.max) {
    const maxDate = new Date(input.max)
    if (date > maxDate) {
      input.value = input.max
      showNotification(`Date adjusted to maximum allowed: ${formatDate(maxDate)}`, "warning")
    }
  }

  // Check related start/end date inputs
  if (input.hasAttribute("data-date-start")) {
    const endInput = document.getElementById(input.getAttribute("data-date-end"))
    if (endInput && endInput.value) {
      const endDate = new Date(endInput.value)
      if (date > endDate) {
        endInput.value = value
        showNotification("End date adjusted to match start date", "info")
      }
    }
  }

  if (input.hasAttribute("data-date-end")) {
    const startInput = document.getElementById(input.getAttribute("data-date-start"))
    if (startInput && startInput.value) {
      const startDate = new Date(startInput.value)
      if (date < startDate) {
        input.value = startInput.value
        showNotification("End date cannot be earlier than start date", "warning")
      }
    }
  }
}

// Initialize date range pickers
function initializeDateRangePickers() {
  const dateRangePickers = document.querySelectorAll(".date-range-picker")

  dateRangePickers.forEach((picker) => {
    const startInput = picker.querySelector('input[data-date-role="start"]')
    const endInput = picker.querySelector('input[data-date-role="end"]')

    if (startInput && endInput) {
      // Link the inputs
      startInput.setAttribute("data-date-end", endInput.id)
      endInput.setAttribute("data-date-start", startInput.id)

      // Set default values if needed
      if (picker.hasAttribute("data-default-range")) {
        const range = picker.getAttribute("data-default-range")

        if (range === "week" && !startInput.value && !endInput.value) {
          const today = new Date()
          const startOfWeek = new Date(today)
          startOfWeek.setDate(today.getDate() - today.getDay())

          const endOfWeek = new Date(today)
          endOfWeek.setDate(today.getDate() + (6 - today.getDay()))

          startInput.value = startOfWeek.toISOString().split("T")[0]
          endInput.value = endOfWeek.toISOString().split("T")[0]
        } else if (range === "month" && !startInput.value && !endInput.value) {
          const today = new Date()
          const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1)
          const endOfMonth = new Date(today.getFullYear(), today.getMonth() + 1, 0)

          startInput.value = startOfMonth.toISOString().split("T")[0]
          endInput.value = endOfMonth.toISOString().split("T")[0]
        }
      }
    }
  })
}

// Format date for display
function formatDate(date) {
  return date.toLocaleDateString("en-US", {
    year: "numeric",
    month: "short",
    day: "numeric",
  })
}

/**
 * Search filters
 */
function initializeSearchFilters() {
  // Initialize search inputs
  const searchInputs = document.querySelectorAll("input[data-search]")

  searchInputs.forEach((input) => {
    input.addEventListener("keyup", function () {
      const target = this.getAttribute("data-search")
      const items = document.querySelectorAll(target)

      if (items.length > 0) {
        const query = this.value.toLowerCase()

        items.forEach((item) => {
          const text = item.textContent.toLowerCase()
          item.style.display = text.includes(query) ? "" : "none"
        })

        // Check if any items are visible
        let hasVisibleItems = false
        items.forEach((item) => {
          if (item.style.display !== "none") {
            hasVisibleItems = true
          }
        })

        // Show/hide no results message
        let noResultsMessage = document.querySelector(`[data-search-no-results="${target}"]`)

        if (!noResultsMessage) {
          noResultsMessage = document.createElement("div")
          noResultsMessage.className = "empty-state"
          noResultsMessage.setAttribute("data-search-no-results", target)
          noResultsMessage.innerHTML = `
                        <i class="fas fa-search"></i>
                        <p>No results found</p>
                    `

          const container = items[0].parentNode
          container.appendChild(noResultsMessage)
        }

        noResultsMessage.style.display = hasVisibleItems ? "none" : "flex"
      }
    })
  })

  // Initialize filter dropdowns
  const filterDropdowns = document.querySelectorAll("select[data-filter]")

  filterDropdowns.forEach((dropdown) => {
    dropdown.addEventListener("change", function () {
      const target = this.getAttribute("data-filter")
      const items = document.querySelectorAll(target)
      const filterValue = this.value

      if (items.length > 0) {
        items.forEach((item) => {
          if (filterValue === "" || filterValue === "all") {
            item.style.display = ""
          } else {
            const itemValue = item.getAttribute("data-filter-value")
            item.style.display = itemValue === filterValue ? "" : "none"
          }
        })

        // Check if any items are visible
        let hasVisibleItems = false
        items.forEach((item) => {
          if (item.style.display !== "none") {
            hasVisibleItems = true
          }
        })

        // Show/hide no results message
        let noResultsMessage = document.querySelector(`[data-filter-no-results="${target}"]`)

        if (!noResultsMessage) {
          noResultsMessage = document.createElement("div")
          noResultsMessage.className = "empty-state"
          noResultsMessage.setAttribute("data-filter-no-results", target)
          noResultsMessage.innerHTML = `
                        <i class="fas fa-filter"></i>
                        <p>No matching items found</p>
                    `

          const container = items[0].parentNode
          container.appendChild(noResultsMessage)
        }

        noResultsMessage.style.display = hasVisibleItems ? "none" : "flex"
      }
    })
  })
}

/**
 * Setup additional event listeners
 */
function setupEventListeners() {
  // Exercise management in workout plans
  setupExerciseManagement()

  // Theme switcher
  setupThemeSwitcher()

  // Tab navigation
  setupTabNavigation()

  // Confirm delete actions
  setupDeleteConfirmations()

  // Status update buttons
  setupStatusUpdates()
}

// Exercise management in workout plans
function setupExerciseManagement() {
  // Add exercise button
  const addExerciseBtn = document.getElementById("addExerciseBtn")
  if (addExerciseBtn) {
    addExerciseBtn.addEventListener("click", () => {
      const container = document.getElementById("exercisesContainer")
      if (container) {
        const exerciseItem = document.createElement("div")
        exerciseItem.className = "exercise-item"
        exerciseItem.innerHTML = `
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Exercise Name</label>
                            <input type="text" name="exercise_name[]" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Sets</label>
                            <input type="number" name="sets[]" class="form-control" min="1" value="3" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Reps</label>
                            <input type="text" name="reps[]" class="form-control" value="10-12" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Rest Time (seconds)</label>
                            <input type="number" name="rest_time[]" class="form-control" min="0" value="60" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="exercise_notes[]" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <button type="button" class="btn btn-sm btn-danger remove-exercise">
                        <i class="fas fa-trash"></i> Remove
                    </button>
                    
                    <hr style="margin: 20px 0;">
                `
        container.appendChild(exerciseItem)

        // Add event listener to the new remove button
        const removeBtn = exerciseItem.querySelector(".remove-exercise")
        if (removeBtn) {
          removeBtn.addEventListener("click", () => {
            container.removeChild(exerciseItem)
          })
        }
      }
    })
  }

  // Remove exercise buttons
  document.addEventListener("click", (e) => {
    if (e.target.classList.contains("remove-exercise") || e.target.closest(".remove-exercise")) {
      const button = e.target.classList.contains("remove-exercise") ? e.target : e.target.closest(".remove-exercise")
      const exerciseItem = button.closest(".exercise-item")

      if (exerciseItem && exerciseItem.parentNode) {
        exerciseItem.parentNode.removeChild(exerciseItem)
      }
    }
  })
}

// Theme switcher
function setupThemeSwitcher() {
  const themeOptions = document.querySelectorAll(".theme-option")

  themeOptions.forEach((option) => {
    option.addEventListener("click", function () {
      // Update radio button
      const radio = this.querySelector('input[type="radio"]')
      if (radio) {
        radio.checked = true
      }

      // Update active class
      themeOptions.forEach((o) => o.classList.remove("active"))
      this.classList.add("active")

      // Apply theme immediately for preview
      const theme = this.getAttribute("data-theme")
      if (theme) {
        document.documentElement.setAttribute("data-theme", theme)
      }
    })
  })
}

// Tab navigation
function setupTabNavigation() {
  const tabButtons = document.querySelectorAll(".tab-btn")

  tabButtons.forEach((button) => {
    button.addEventListener("click", function () {
      const tabId = this.getAttribute("data-tab")

      // Update active tab button
      tabButtons.forEach((btn) => btn.classList.remove("active"))
      this.classList.add("active")

      // Show the selected tab content
      const tabContents = document.querySelectorAll(".tab-content")
      tabContents.forEach((content) => {
        content.classList.remove("active")
      })

      const selectedTab = document.getElementById(`${tabId}-tab`)
      if (selectedTab) {
        selectedTab.classList.add("active")
      }
    })
  })
}

// Confirm delete actions
function setupDeleteConfirmations() {
  // Show delete confirmation modal
  window.confirmDelete = (id) => {
    const deleteIdInput = document.getElementById("delete_progress_id") || document.getElementById("delete_session_id")
    if (deleteIdInput) {
      deleteIdInput.value = id
    }

    const modal = document.getElementById("deleteConfirmModal")
    if (modal) {
      openModal(modal)
    }
  }
}

// Status update buttons
function setupStatusUpdates() {
  // Show status update modal
  window.showStatusModal = (id, currentStatus) => {
    const statusIdInput = document.getElementById("status_session_id")
    const statusSelect = document.getElementById("status")

    if (statusIdInput && statusSelect) {
      statusIdInput.value = id
      statusSelect.value = currentStatus
    }

    const modal = document.getElementById("updateStatusModal")
    if (modal) {
      openModal(modal)
    }
  }
}

/**
 * Handle URL parameters
 */
function handleUrlParameters() {
  const urlParams = new URLSearchParams(window.location.search)

  // Handle success messages
  if (urlParams.has("created") && urlParams.get("created") === "1") {
    showNotification("Item created successfully", "success")
  }

  if (urlParams.has("updated") && urlParams.get("updated") === "1") {
    showNotification("Item updated successfully", "success")
  }

  if (urlParams.has("deleted") && urlParams.get("deleted") === "1") {
    showNotification("Item deleted successfully", "success")
  }

  // Handle edit parameter
  if (urlParams.has("edit")) {
    const editId = urlParams.get("edit")
    const editModal = document.getElementById("editSessionModal") || document.getElementById("editWorkoutModal")

    if (editModal) {
      openModal(editModal)
    }
  }

  // Handle date parameter for schedule
  if (urlParams.has("date")) {
    const dateParam = urlParams.get("date")
    const dateInput = document.querySelector('input[type="date"][name="date"]')

    if (dateInput) {
      dateInput.value = dateParam
    }
  }

  // Handle member_id parameter
  if (urlParams.has("member_id")) {
    const memberId = urlParams.get("member_id")
    const memberSelect = document.getElementById("member_select") || document.getElementById("member_id")

    if (memberSelect) {
      memberSelect.value = memberId
    }
  }
}

// Initialize everything when the DOM is fully loaded
document.addEventListener("DOMContentLoaded", () => {
  console.log("EliteFit Gym Trainer Dashboard initialized")
})
