import SwiftUI

struct CalendarView: View {
    @State private var selectedDate = Date()
    @State private var events: [CalendarEvent] = []
    @State private var bookings: [Booking] = []
    @State private var isLoading = false
    @State private var showCreateEvent = false

    private var selectedDateStr: String {
        DateFormatters.apiDate.string(from: selectedDate)
    }

    private var filteredEvents: [CalendarEvent] {
        events.filter { $0.start.hasPrefix(selectedDateStr) || $0.allDay }
    }

    private var filteredBookings: [Booking] {
        bookings.filter { booking in
            guard let s = booking.startDate, let e = booking.endDate else { return false }
            return s <= selectedDate.addingTimeInterval(86399) && e >= selectedDate
        }
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                // Native date picker as calendar
                DatePicker(
                    "Select date",
                    selection: $selectedDate,
                    displayedComponents: .date
                )
                .datePickerStyle(.graphical)
                .padding(.horizontal)
                .onChange(of: selectedDate) { _, _ in
                    Task { await loadMonth() }
                }

                Divider()

                // Events for selected day
                List {
                    if filteredEvents.isEmpty && filteredBookings.isEmpty {
                        ContentUnavailableView(
                            "No Events",
                            systemImage: "calendar.badge.exclamationmark",
                            description: Text("Nothing scheduled for \(DateFormatters.shortDay.string(from: selectedDate))")
                        )
                    }

                    if !filteredEvents.isEmpty {
                        Section("Events") {
                            ForEach(filteredEvents) { event in
                                EventRow(event: event)
                            }
                        }
                    }

                    if !filteredBookings.isEmpty {
                        Section("Bookings") {
                            ForEach(filteredBookings) { booking in
                                BookingRow(booking: booking)
                            }
                        }
                    }
                }
                .listStyle(.plain)
            }
            .navigationTitle("Calendar")
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Button { showCreateEvent = true } label: {
                        Image(systemName: "plus")
                    }
                }
            }
            .sheet(isPresented: $showCreateEvent) {
                CreateEventView(selectedDate: selectedDate) {
                    Task { await loadMonth() }
                }
            }
            .task { await loadMonth() }
        }
    }

    private func loadMonth() async {
        isLoading = true
        let cal = Calendar.current
        let start = cal.date(from: cal.dateComponents([.year, .month], from: selectedDate))!
        let end = cal.date(byAdding: .month, value: 1, to: start)!

        let startStr = DateFormatters.apiDate.string(from: start)
        let endStr = DateFormatters.apiDate.string(from: end)

        do {
            async let evTask = APIService.shared.fetchEvents(start: startStr, end: endStr)
            async let bkTask = APIService.shared.fetchBookings(start: startStr, end: endStr)
            let (ev, bk) = try await (evTask, bkTask)
            events = ev
            bookings = bk
        } catch {
            print("Calendar load error: \(error)")
        }
        isLoading = false
    }
}

// MARK: - Event Row

struct EventRow: View {
    let event: CalendarEvent

    var body: some View {
        HStack(spacing: 10) {
            Circle()
                .fill(event.source == "DutySheet" ? .orange : .pink)
                .frame(width: 8, height: 8)

            VStack(alignment: .leading, spacing: 2) {
                Text(event.title)
                    .font(.subheadline)
                    .fontWeight(.medium)

                HStack(spacing: 8) {
                    if !event.allDay, let d = event.startDate {
                        Text(DateFormatters.time.string(from: d))
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    } else {
                        Text("All day")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    if !event.location.isEmpty {
                        Label(event.location, systemImage: "mappin")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                            .lineLimit(1)
                    }
                }
            }

            Spacer()
        }
        .padding(.vertical, 4)
    }
}

// MARK: - Booking Row

struct BookingRow: View {
    let booking: Booking

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: "house.fill")
                .foregroundStyle(.purple)

            VStack(alignment: .leading, spacing: 2) {
                Text(booking.guests.map(\.name).joined(separator: ", "))
                    .font(.subheadline)
                    .fontWeight(.medium)

                HStack(spacing: 8) {
                    if !booking.occasion.isEmpty {
                        Text(booking.occasion)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    Text(booking.status)
                        .font(.caption)
                        .foregroundStyle(booking.status.lowercased() == "confirmed" ? .green : .orange)
                }
            }
            Spacer()
        }
        .padding(.vertical, 4)
    }
}

// MARK: - Create Event

struct CreateEventView: View {
    let selectedDate: Date
    let onCreated: () -> Void
    @Environment(\.dismiss) private var dismiss

    @State private var title = ""
    @State private var location = ""
    @State private var startTime = Date()
    @State private var endTime = Date()
    @State private var allDay = false
    @State private var isSaving = false

    var body: some View {
        NavigationStack {
            Form {
                TextField("Event title", text: $title)

                Toggle("All Day", isOn: $allDay)

                if !allDay {
                    DatePicker("Start", selection: $startTime)
                    DatePicker("End", selection: $endTime)
                } else {
                    DatePicker("Date", selection: $startTime, displayedComponents: .date)
                }

                TextField("Location (optional)", text: $location)
            }
            .navigationTitle("New Event")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { Task { await save() } }
                        .disabled(title.isEmpty || isSaving)
                }
            }
        }
        .onAppear {
            startTime = selectedDate
            endTime = selectedDate.addingTimeInterval(3600)
        }
    }

    private func save() async {
        isSaving = true
        let startStr = allDay
            ? DateFormatters.apiDate.string(from: startTime) + " 00:00:00"
            : DateFormatters.apiDateTime.string(from: startTime)
        let endStr = allDay
            ? nil
            : DateFormatters.apiDateTime.string(from: endTime)

        let request = CreateEventRequest(
            title: title,
            start: startStr,
            end: endStr,
            location: location.isEmpty ? nil : location,
            allDay: allDay,
            source: "Mobile"
        )

        do {
            _ = try await APIService.shared.createEvent(request)
            onCreated()
            dismiss()
        } catch {
            print("Create event error: \(error)")
        }
        isSaving = false
    }
}
