import SwiftUI

struct DashboardView: View {
    @EnvironmentObject var authManager: AuthManager
    @State private var weekDays: [DailyOpsDay] = []
    @State private var events: [CalendarEvent] = []
    @State private var bookings: [Booking] = []
    @State private var isLoading = true
    @State private var weekOffset = 0

    private var weekStart: Date {
        let cal = Calendar(identifier: .iso8601)
        let now = Date()
        let monday = cal.date(from: cal.dateComponents([.yearForWeekOfYear, .weekOfYear], from: now))!
        return cal.date(byAdding: .weekOfYear, value: weekOffset, to: monday)!
    }

    private var weekEnd: Date {
        Calendar.current.date(byAdding: .day, value: 6, to: weekStart)!
    }

    private var weekTitle: String {
        let s = DateFormatters.shortDay.string(from: weekStart)
        let e = DateFormatters.shortDay.string(from: weekEnd)
        return "\(s) – \(e)"
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    // Week navigation
                    weekNavigator

                    if isLoading {
                        ProgressView()
                            .padding(.top, 40)
                    } else {
                        // Daily ops cards
                        LazyVStack(spacing: 12) {
                            ForEach(weekDays) { day in
                                DayCard(day: day, events: eventsFor(date: day.date), bookings: bookingsFor(date: day.date))
                            }
                        }
                    }
                }
                .padding()
            }
            .background(Color(.systemGroupedBackground))
            .navigationTitle("Dashboard")
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    Menu {
                        Button("Logout", role: .destructive) { authManager.logout() }
                    } label: {
                        Image(systemName: "person.circle")
                    }
                }
            }
            .task { await loadWeek() }
            .refreshable { await loadWeek() }
        }
    }

    private var weekNavigator: some View {
        HStack {
            Button { weekOffset -= 1; Task { await loadWeek() } } label: {
                Image(systemName: "chevron.left")
                    .fontWeight(.semibold)
            }

            Spacer()

            VStack(spacing: 2) {
                Text(weekTitle)
                    .font(.headline)
                if weekOffset == 0 {
                    Text("This week")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            Spacer()

            Button { weekOffset += 1; Task { await loadWeek() } } label: {
                Image(systemName: "chevron.right")
                    .fontWeight(.semibold)
            }
        }
        .padding(.horizontal, 4)
    }

    private func eventsFor(date: String) -> [CalendarEvent] {
        events.filter { $0.start.hasPrefix(date) }
    }

    private func bookingsFor(date: String) -> [Booking] {
        bookings.filter { booking in
            guard let s = booking.startDate, let e = booking.endDate else { return false }
            guard let d = DateFormatters.apiDate.date(from: date) else { return false }
            return s <= d.addingTimeInterval(86399) && e >= d
        }
    }

    private func loadWeek() async {
        isLoading = true
        let startStr = DateFormatters.apiDate.string(from: weekStart)
        let endStr = DateFormatters.apiDate.string(from: weekEnd)

        async let opsTask = APIService.shared.fetchDailyOps(start: startStr, end: endStr)
        async let eventsTask = APIService.shared.fetchEvents(start: startStr, end: endStr)
        async let bookingsTask = APIService.shared.fetchBookings(start: startStr, end: endStr)

        do {
            let (ops, ev, bk) = try await (opsTask, eventsTask, bookingsTask)
            weekDays = ops
            events = ev
            bookings = bk
        } catch {
            print("Dashboard load error: \(error)")
        }
        isLoading = false
    }
}

// MARK: - Day Card

struct DayCard: View {
    let day: DailyOpsDay
    let events: [CalendarEvent]
    let bookings: [Booking]

    private var isToday: Bool {
        day.date == DateFormatters.apiDate.string(from: Date())
    }

    private var dayLabel: String {
        guard let d = day.dateValue else { return day.date }
        return DateFormatters.shortDay.string(from: d)
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            // Header
            HStack {
                Text(dayLabel)
                    .font(.headline)
                    .fontWeight(.bold)
                if isToday {
                    Text("TODAY")
                        .font(.caption2)
                        .fontWeight(.bold)
                        .padding(.horizontal, 6)
                        .padding(.vertical, 2)
                        .background(.blue)
                        .foregroundStyle(.white)
                        .clipShape(Capsule())
                }
                Spacer()

                // On-call indicators
                if day.ckOnCall {
                    Label("CK", systemImage: "phone.fill")
                        .font(.caption2)
                        .foregroundStyle(.red)
                }
                if day.dcOnCall {
                    Label("DC", systemImage: "phone.fill")
                        .font(.caption2)
                        .foregroundStyle(.red)
                }
            }

            // Locations row
            HStack(spacing: 16) {
                if !day.ckLocation.name.isEmpty || !day.ckWorkLocation.name.isEmpty {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("CK")
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                        if !day.ckWorkLocation.name.isEmpty {
                            Label(day.ckWorkLocation.name, systemImage: "briefcase.fill")
                                .font(.caption)
                        }
                        if !day.ckLocation.name.isEmpty {
                            Label(day.ckLocation.name, systemImage: "moon.fill")
                                .font(.caption)
                        }
                    }
                }

                if !day.dcLocation.name.isEmpty || !day.dcWorkLocation.name.isEmpty {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("DC")
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                        if !day.dcWorkLocation.name.isEmpty {
                            Label(day.dcWorkLocation.name, systemImage: "briefcase.fill")
                                .font(.caption)
                        }
                        if !day.dcLocation.name.isEmpty {
                            Label(day.dcLocation.name, systemImage: "moon.fill")
                                .font(.caption)
                        }
                    }
                }
            }

            // Meal/notes
            if !day.notes.isEmpty {
                Label(day.notes, systemImage: "fork.knife")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }

            // Events
            ForEach(events) { event in
                HStack(spacing: 6) {
                    Circle()
                        .fill(event.source == "DutySheet" ? .orange : .pink)
                        .frame(width: 6, height: 6)
                    Text(event.title)
                        .font(.caption)
                        .lineLimit(1)
                    Spacer()
                    if !event.allDay, let d = event.startDate {
                        Text(DateFormatters.time.string(from: d))
                            .font(.caption2)
                            .foregroundStyle(.secondary)
                    }
                }
            }

            // Bookings
            ForEach(bookings) { booking in
                HStack(spacing: 6) {
                    Image(systemName: "house.fill")
                        .font(.caption2)
                        .foregroundStyle(.purple)
                    Text(booking.guests.map(\.name).joined(separator: ", "))
                        .font(.caption)
                        .lineLimit(1)
                }
            }
        }
        .padding()
        .background(.white)
        .clipShape(RoundedRectangle(cornerRadius: 14))
        .shadow(color: .black.opacity(0.06), radius: 8, x: 0, y: 4)
        .overlay(
            RoundedRectangle(cornerRadius: 14)
                .stroke(isToday ? .blue.opacity(0.3) : .clear, lineWidth: 2)
        )
    }
}
