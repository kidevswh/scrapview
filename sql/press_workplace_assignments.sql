if object_id('dbo.press_workplace_assignments', 'U') is null
begin
    create table dbo.press_workplace_assignments (
        id int identity(1,1) not null primary key,
        hostname nvarchar(255) not null,
        press_id nvarchar(40) not null,
        workplace_label nvarchar(120) null,
        is_active bit not null default 1,
        created_at datetime2 not null default sysdatetime(),
        updated_at datetime2 null
    );
end;

if not exists (
    select 1
    from sys.indexes
    where name = N'IX_press_workplace_assignments_hostname'
      and object_id = object_id(N'dbo.press_workplace_assignments', N'U')
)
begin
    create index IX_press_workplace_assignments_hostname
    on dbo.press_workplace_assignments (hostname, is_active, press_id);
end;

-- Beispiel:
-- insert into dbo.press_workplace_assignments (hostname, press_id, workplace_label)
-- values ('PRESS-PC-01', 'P1', 'Arbeitsplatz Presse 1');
